<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Models\Announcement;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Status;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Announcements: the module that has to keep AUDIENCE and AUTHORIZATION apart (§18, §90).
 *
 * A memo asks "who was this sent to". A noticeboard asks two questions that look alike and are not:
 *
 *   MAY I read this        — the scope compiler plus the draft curtain on `published_at`. A boundary.
 *   IS this meant for me   — Announcement::scopeAddressedTo(). A filter, and the direction is the
 *                            opposite one: reach runs DOWN the org tree, audience runs UP, so a
 *                            notice pinned to Engineering is for everybody inside Engineering.
 *
 * Confuse the two and the failure is silent either way round: fold audience into authorization and
 * the archive stops working for anyone outside the addressed unit; fold authorization into audience
 * and a department's notice is served to a company that was never given sight of it. Both directions
 * are asserted below, on the same notice, from the same account.
 *
 * The second theme is that a notice is LIVE where a memo is FROZEN. It stays editable after
 * publication — a closure date that moves should be corrected in place rather than superseded by a
 * second notice nobody reads — but re-aiming it is a publishing decision, not a correction, and
 * archiving is terminal.
 *
 *   Engineering (division)
 *     └── Civil            Ngozi   — reader: `announcement.view: organization` and nothing else
 *                          Kunle   — narrow reader: `announcement.view: department`, plus create
 *     Commercial           Yemi    — may correct a notice, may NOT publish or re-aim one
 *     Executive            Dele    — communications: view/create/edit/delete/publish, organization
 */
final class AnnouncementAccessTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private OrgUnit $civil;

    private OrgUnit $commercial;

    private User $dele;      // writes and publishes

    private User $ngozi;     // Civil, reads the whole board

    private User $kunle;     // Civil, reads only what is filed against Civil

    private User $yemi;      // Commercial, may edit but not publish

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $this->engineering);
        $this->commercial = $this->makeUnit($this->org, 'Commercial', OrgUnitType::Department);
        $executive = $this->makeUnit($this->org, 'Executive', OrgUnitType::Department);

        $communications = $this->makeRole($this->org, 'Communications', [
            'announcement.view' => 'organization',
            'announcement.create' => 'organization',
            'announcement.edit' => 'organization',
            'announcement.delete' => 'organization',
            'announcement.publish' => 'organization',
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
        ]);

        // The shipped baseline: everybody can read the board, and that is the whole grant.
        $readerRole = $this->makeRole($this->org, 'Staff', [
            'announcement.view' => 'organization',
        ]);

        // May correct a live notice. Deliberately no `announcement.publish`, which is the split the
        // re-aiming test turns on.
        $editorRole = $this->makeRole($this->org, 'Department Administrator', [
            'announcement.view' => 'organization',
            'announcement.edit' => 'organization',
            'org_unit.view' => 'organization',
        ]);

        // A configured narrowing of the default. Reading a noticeboard department by department is
        // unusual, and it has to still work: the addressing columns are audience AND scope.
        $narrowRole = $this->makeRole($this->org, 'Site Clerk', [
            'announcement.view' => 'department',
            'announcement.create' => 'department',
            'org_unit.view' => 'department',
        ]);

        $this->dele = $this->makePerson($this->org, 'Dele Ajayi', $executive, [$communications]);
        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$readerRole]);
        $this->kunle = $this->makePerson($this->org, 'Kunle Sanni', $this->civil, [$narrowRole]);
        $this->yemi = $this->makePerson($this->org, 'Yemi Adeyemi', $this->commercial, [$editorRole]);
    }

    // ------------------------------------------------------------------ the draft curtain

    #[Test]
    public function a_notice_nobody_has_published_is_not_on_the_board(): void
    {
        $notice = $this->draft();

        $this->actingFresh($this->dele)->get(route('announcements.show', $notice))->assertOk();

        // Organization-wide `announcement.view`, and still nothing: an unpublished notice is not
        // something the company has been told.
        $this->actingFresh($this->ngozi)->get(route('announcements.show', $notice))->assertForbidden();

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'all']))
            ->assertOk()
            ->assertDontSee($notice->number);
    }

    #[Test]
    public function publishing_is_what_puts_it_on_the_board(): void
    {
        $notice = $this->draft();

        $this->actingFresh($this->ngozi)->get(route('announcements.show', $notice))->assertForbidden();

        $notice = $this->publishAnnouncement($this->org, $notice, $this->dele);

        $this->actingFresh($this->ngozi)->get(route('announcements.show', $notice))->assertOk();

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'live']))
            ->assertOk()
            ->assertSee($notice->number);
    }

    #[Test]
    public function unpublishing_puts_it_back_behind_the_curtain(): void
    {
        $notice = $this->publishAnnouncement($this->org, $this->draft(), $this->dele);

        $this->actingFresh($this->ngozi)->get(route('announcements.show', $notice))->assertOk();

        $this->actingFresh($this->dele)
            ->post(route('announcements.status', $notice), [
                'status_id' => $this->statusFor('draft')->id,
                'comment' => 'Posted before the closure was confirmed.',
            ])
            ->assertRedirect();

        $fresh = $this->reload($notice);
        $this->assertNull($fresh->published_at, 'coming off the board must clear the publication stamp');

        $this->actingFresh($this->ngozi)->get(route('announcements.show', $fresh))->assertForbidden();
    }

    // ------------------------------------------------------------------ audience vs authorization

    /**
     * The direction test. Ngozi sits in Civil, one level inside Engineering.
     *
     * A notice pinned to Engineering is FOR her, because audience reads up the tree. If this ever
     * inverts to match reach — which reads down — then a division's notices reach only the division
     * office and every team below it is left off the board it was written for.
     */
    #[Test]
    public function the_board_reads_up_the_tree(): void
    {
        $notice = $this->publishAnnouncement(
            $this->org,
            $this->draft(['org_unit_id' => $this->engineering->id]),
            $this->dele,
        );

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'current']))
            ->assertOk()
            ->assertSee($notice->number);
    }

    /**
     * The same notice, the same reader, the other question.
     *
     * A Commercial notice is not on a Civil engineer's board and is not hidden from her either. That
     * is a noticeboard: narrowing by audience is routing, and the module never claims it as a
     * security control (§61 — the archive shows what it shows to everyone who may see it).
     */
    #[Test]
    public function a_notice_aimed_elsewhere_is_off_my_board_without_being_kept_from_me(): void
    {
        $notice = $this->publishAnnouncement(
            $this->org,
            $this->draft(['org_unit_id' => $this->commercial->id]),
            $this->dele,
        );

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'current']))
            ->assertOk()
            ->assertDontSee($notice->number);

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'live']))
            ->assertOk()
            ->assertSee($notice->number);

        $this->actingFresh($this->ngozi)->get(route('announcements.show', $notice))->assertOk();
    }

    /**
     * And where the grant IS narrow, the addressing columns bite as scope as well as audience.
     *
     * The default role reads the whole board, so this configuration is unusual — but an organization
     * that narrows `announcement.view` to `department` means it, and the engine must not treat a
     * noticeboard as a special case exempt from the compiler.
     */
    #[Test]
    public function a_narrowed_grant_still_narrows(): void
    {
        $notice = $this->publishAnnouncement(
            $this->org,
            $this->draft(['org_unit_id' => $this->commercial->id]),
            $this->dele,
        );

        $this->actingFresh($this->kunle)->get(route('announcements.show', $notice))->assertForbidden();

        $mine = $this->publishAnnouncement(
            $this->org,
            $this->draft(['org_unit_id' => $this->civil->id]),
            $this->dele,
        );

        $this->actingFresh($this->kunle)->get(route('announcements.show', $mine))->assertOk();
    }

    #[Test]
    public function an_expired_notice_comes_off_the_board_without_being_deleted(): void
    {
        // Set on the fixture rather than through the form: the request refuses a date already past,
        // which is right for authoring and would make this state unreachable in a test.
        $notice = $this->publishAnnouncement(
            $this->org,
            $this->draft(['expires_on' => now()->subDay()->toDateString()]),
            $this->dele,
        );

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'current']))
            ->assertOk()
            ->assertDontSee($notice->number);

        $this->actingFresh($this->ngozi)->get(route('announcements.show', $notice))->assertOk();
    }

    // ------------------------------------------------------------------ live, not frozen

    #[Test]
    public function a_published_notice_can_still_be_corrected(): void
    {
        $notice = $this->publishAnnouncement($this->org, $this->draft(), $this->dele);

        // Yemi holds `announcement.edit` and not `announcement.publish`. Correcting the text of a
        // live notice is exactly what that grant is for — the contrast with an issued memo, which
        // nobody may edit at any scope.
        $this->actingFresh($this->yemi)
            ->put(route('announcements.update', $notice), $this->payload($notice, [
                'body' => 'The north gate is now closed until Monday.',
            ]))
            ->assertRedirect(route('announcements.show', $notice));

        $this->assertSame('The north gate is now closed until Monday.', $this->reload($notice)->body);
    }

    /**
     * Re-aiming is a publishing decision, not a correction.
     *
     * Moving a live notice from one department to another broadcasts it at people who were never in
     * its audience. Somebody trusted to fix a date is not thereby trusted to do that.
     */
    #[Test]
    public function re_aiming_a_live_notice_needs_the_publish_permission(): void
    {
        $notice = $this->publishAnnouncement(
            $this->org,
            $this->draft(['org_unit_id' => $this->civil->id]),
            $this->dele,
        );

        $this->actingFresh($this->yemi)
            ->put(route('announcements.update', $notice), $this->payload($notice, [
                'org_unit_id' => $this->commercial->id,
            ]))
            ->assertForbidden();

        $this->assertSame($this->civil->id, $this->reload($notice)->org_unit_id);

        $this->actingFresh($this->dele)
            ->put(route('announcements.update', $notice), $this->payload($notice, [
                'org_unit_id' => $this->commercial->id,
            ]))
            ->assertRedirect();

        $this->assertSame($this->commercial->id, $this->reload($notice)->org_unit_id);
    }

    #[Test]
    public function an_archived_notice_is_history_and_history_is_not_edited(): void
    {
        $notice = $this->publishAnnouncement($this->org, $this->draft(), $this->dele);

        $this->actingFresh($this->dele)
            ->post(route('announcements.status', $notice), ['status_id' => $this->statusFor('archived')->id])
            ->assertRedirect();

        $archived = $this->reload($notice);
        $this->assertSame('archived', $archived->statusKey());

        $this->actingFresh($this->dele)->get(route('announcements.edit', $archived))->assertForbidden();

        $this->actingFresh($this->dele)
            ->put(route('announcements.update', $archived), $this->payload($archived, [
                'body' => 'Rewriting what the company was told last quarter.',
            ]))
            ->assertForbidden();
    }

    #[Test]
    public function a_published_notice_cannot_be_deleted_but_a_draft_can(): void
    {
        $published = $this->publishAnnouncement($this->org, $this->draft(), $this->dele);

        $this->actingFresh($this->dele)->delete(route('announcements.destroy', $published))->assertForbidden();
        $this->assertNotNull($this->find($published->id));

        $draft = $this->draft(['title' => 'Never sent']);

        $this->actingFresh($this->dele)->delete(route('announcements.destroy', $draft))->assertRedirect();
        $this->assertNull($this->find($draft->id));
    }

    // ------------------------------------------------------------------ authoring

    #[Test]
    public function publishing_needs_the_publish_permission_rather_than_the_edit_permission(): void
    {
        $notice = $this->draft();

        $this->actingFresh($this->yemi)
            ->post(route('announcements.status', $notice), ['status_id' => $this->statusFor('published')->id])
            ->assertForbidden();

        $this->assertNull($this->reload($notice)->published_at);
    }

    /**
     * Aiming a notice at a department is not reading one there — but pinning a notice to a unit you
     * have no standing in would broadcast into a part of the company you cannot see, so the
     * controller refuses it on the same test (§84).
     */
    #[Test]
    public function a_notice_cannot_be_pinned_to_a_department_the_author_cannot_reach(): void
    {
        $this->actingFresh($this->kunle)
            ->post(route('announcements.store'), [
                'title' => 'Aimed somewhere I have no standing',
                'body' => 'Please note the following.',
                'urgency' => 'normal',
                'org_unit_id' => $this->commercial->id,
            ])
            ->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => Announcement::query()
            ->where('org_unit_id', $this->commercial->id)->count()));
    }

    #[Test]
    public function the_search_box_matches_the_title_but_never_the_body(): void
    {
        $notice = $this->publishAnnouncement($this->org, $this->draft([
            'title' => 'Temporary access road closure',
            'body' => 'The haul route past the batching plant is out of use.',
        ]), $this->dele);

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'all', 'q' => 'access road']))
            ->assertOk()
            ->assertSee($notice->number);

        $this->actingFresh($this->ngozi)
            ->get(route('announcements.index', ['view' => 'all', 'q' => 'batching plant']))
            ->assertOk()
            ->assertDontSee($notice->number);
    }

    // ------------------------------------------------------------------ tenancy

    #[Test]
    public function another_organization_gets_a_404_rather_than_a_refusal(): void
    {
        $other = $this->makeOrganization('Kestrel Infrastructure');
        $unit = $this->makeUnit($other, 'Operations', OrgUnitType::Department);

        $role = $this->makeRole($other, 'Communications', [
            'announcement.view' => 'organization',
            'announcement.create' => 'organization',
            'announcement.publish' => 'organization',
        ]);

        $stranger = $this->makePerson($other, 'Ruth Iwu', $unit, [$role]);

        $theirs = $this->publishAnnouncement(
            $other,
            $this->makeAnnouncement($other, $stranger, 'Their shutdown week', ['org_unit_id' => $unit->id]),
            $stranger,
        );

        // A published notice is the most widely readable record the system holds, and it still stops
        // at the tenant boundary — as a 404, so the id is not confirmed to exist (§75).
        $this->actingFresh($this->ngozi)->get(route('announcements.show', $theirs->id))->assertNotFound();
        $this->actingFresh($stranger)->get(route('announcements.show', $this->draft()->id))->assertNotFound();
    }

    // ------------------------------------------------------------------ fixture helpers

    /** @param array<string,mixed> $attributes */
    private function draft(array $attributes = []): Announcement
    {
        return $this->makeAnnouncement($this->org, $this->dele, 'Site induction moves to Monday mornings', $attributes);
    }

    /**
     * The whole form, with the given fields changed.
     *
     * A PUT that posted only the edited field would be testing a partial update the form never
     * sends, and would trip the request's `required` rules rather than the authorization the test is
     * actually about.
     *
     * @param  array<string,mixed>  $changes
     * @return array<string,mixed>
     */
    private function payload(Announcement $announcement, array $changes = []): array
    {
        return [
            'title' => $announcement->title,
            'body' => $announcement->body,
            'urgency' => $announcement->urgency,
            'is_pinned' => $announcement->is_pinned ? '1' : '0',
            'org_unit_id' => $announcement->org_unit_id,
            'project_id' => $announcement->project_id,
            ...$changes,
        ];
    }

    private function reload(Announcement $announcement): Announcement
    {
        return $this->withinOrganization($this->org, fn () => Announcement::findOrFail($announcement->id));
    }

    private function find(int $id): ?Announcement
    {
        return $this->withinOrganization($this->org, fn () => Announcement::find($id));
    }

    private function statusFor(string $key): Status
    {
        return $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity('announcement')
            ->where('key', $key)
            ->firstOrFail());
    }
}
