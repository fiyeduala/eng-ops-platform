<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Engines\Access\ActorProfile;
use App\Engines\Expiry\ExpirySweeper;
use App\Events\RecordExpiring;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\ExpiryWatch;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Status;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use App\Support\ExpiryCatalogue;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * The generic expiry watch: what a company asked to be warned about, and who is allowed to hear it.
 *
 * THIS REPLACED TWO HARD-CODED SWEEPS that between them covered two of the eleven expiry dates in
 * the platform — maintenance due dates and vehicle paperwork — for no better reason than that those
 * were the two somebody had written a sweep for. Permits, competency certificates, vendor
 * prequalifications and document review dates all lapse the same way and none of them was watched.
 * So the interesting assertions here are not "does it find a date"; they are the four properties a
 * configurable engine has to hold that a hard-coded one got for free:
 *
 *   THE COMPANY'S MILESTONES ARE OBEYED, exactly — a watch speaks on the days it was told to and is
 *   silent between them. The old sweeps warned every day for the whole window, so a sixty-day
 *   setting meant sixty identical e-mails about one certificate, and a warning that arrives daily is
 *   a warning somebody writes a mail rule for.
 *
 *   WHAT HAPPENS AFTER THE DATE IS A DECISION, not a rule. A lapsed insurance certificate on a truck
 *   still being driven must keep speaking; a maintenance job whose due date slipped should not chase
 *   somebody forever. Both are `warn_when_overdue`, and the company sets it.
 *
 *   A SWEEP IS STILL BOUND BY THE TENANT (§75) AND BY AUTHORIZATION (§68). A nightly job running
 *   with no signed-in user is the easiest place in a codebase to leak one company's records into
 *   another's warnings, and being NAMED on a watch is not the same as being allowed to read the
 *   record the warning is about.
 *
 *   NOTHING FROM A SELECT BOX REACHES A QUERY UNCHECKED (§84). `date_field` ends up inside a `where`,
 *   so a column name that arrived from the browser unvalidated would be a way to ask questions about
 *   any column on the table.
 *
 * The fixture is the document register, because it is the one with a status vocabulary, a
 * confidentiality model and a department split already tested elsewhere — so an assertion here about
 * who was warned can be read against DocumentAccessTest's assertion about who can read, and the two
 * must agree.
 *
 *   Civil (dept)    Ngozi   document controller, Civil only
 *   Piping (dept)   Sade    engineer, Piping only — cannot read a Civil document
 */
final class ExpiryWatchTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $piping;

    private DocumentCategory $procedures;

    private User $ngozi;

    private User $sade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->piping = $this->makeUnit($this->org, 'Piping', OrgUnitType::Department, $engineering);

        $controller = $this->makeRole($this->org, 'Document Controller', [
            'document.view' => 'department',
            'document.create' => 'department',
            'document.edit' => 'department',
            'document_category.view' => 'organization',
            'org_unit.view' => 'organization',
            'user.view' => 'organization',
        ]);

        $engineer = $this->makeRole($this->org, 'Engineer', [
            'document.view' => 'department',
        ]);

        $this->ngozi = $this->makePerson($this->org, 'Ngozi Eze', $this->civil, [$controller]);
        $this->sade = $this->makePerson($this->org, 'Sade Ajayi', $this->piping, [$engineer]);

        $this->procedures = $this->makeDocumentCategory($this->org, 'Procedures', [
            'requires_approval' => false,
        ]);
    }

    // ================================================================== the milestones

    #[Test]
    public function a_watch_speaks_on_the_days_the_company_chose_and_is_silent_between_them(): void
    {
        $this->reviewDue(7);
        $watch = $this->watch(['lead_days' => [30, 7, 1]]);

        // Seven days out is a milestone.
        $this->assertSame(1, $this->sweep($watch));

        // Six is not. The document has not changed; the company's answer to "warn me when" has.
        $this->reviewDue(6);
        $this->assertSame(0, $this->sweep($watch));

        $this->reviewDue(1);
        $this->assertSame(1, $this->sweep($watch));

        // And a date beyond the furthest milestone is not even loaded.
        $this->reviewDue(90);
        $this->assertSame(0, $this->sweep($watch));
    }

    #[Test]
    public function the_days_are_the_company_s_and_not_the_platform_s(): void
    {
        $this->reviewDue(45);

        $this->assertSame(0, $this->sweep($this->watch(['lead_days' => [30, 7, 1]])));

        // A different company, or the same one having thought about it, gets a different answer to
        // the same database — which is the entire point of the part.
        $this->assertSame(1, $this->sweep($this->watch(['lead_days' => [45, 10]])));
    }

    #[Test]
    public function what_happens_after_the_date_has_passed_is_a_decision_rather_than_a_rule(): void
    {
        $this->reviewDue(-12);

        // A review date that slipped is somebody's admin backlog; chasing it daily forever is how a
        // register's warnings get filtered into a folder nobody opens.
        $this->assertSame(0, $this->sweep($this->watch(['warn_when_overdue' => false])));

        // An expired permit or a lapsed insurance certificate is the opposite case: the record going
        // stale IS the hazard, and the warning has to survive the date it was warning about.
        $this->assertSame(1, $this->sweep($this->watch(['warn_when_overdue' => true])));
    }

    #[Test]
    public function an_overdue_record_is_chased_every_day_rather_than_only_on_milestones(): void
    {
        $watch = $this->watch(['lead_days' => [30, 7, 1], 'warn_when_overdue' => true]);

        // None of -3, -4 or -19 is a milestone, and all three must still speak. The milestone rule
        // exists so nobody is told the same thing six times before the date; after it, silence is
        // the failure mode that costs something.
        foreach ([-3, -4, -19] as $daysPast) {
            $this->reviewDue($daysPast);
            $this->assertSame(1, $this->sweep($watch), 'a lapsed record fell silent '.abs($daysPast).' days after the date');
        }
    }

    // ================================================================== what is left out

    #[Test]
    public function a_record_that_is_finished_with_stops_being_mentioned(): void
    {
        $document = $this->reviewDue(7);
        $watch = $this->watch(['lead_days' => [7]]);

        $this->assertSame(1, $this->sweep($watch));

        // Archived is terminal in this company's own vocabulary, read from the StatusMachine rather
        // than from a list in the engine — so a company that invents "withdrawn" gets it honoured
        // here without anybody adding it to a list (§63).
        $this->moveDocumentTo($document, 'archived');

        $this->assertSame(0, $this->sweep($watch));
    }

    #[Test]
    public function a_watch_that_was_switched_off_does_not_speak(): void
    {
        $this->reviewDue(7);
        $this->watch(['lead_days' => [7], 'is_active' => false]);

        // Through sweepCurrentTenant() rather than sweepWatch(), because "active" is a question the
        // nightly run asks and a direct sweep of one watch deliberately does not.
        $this->assertSame(0, $this->sweepEverything());
    }

    // ================================================================== §75 — one company at a time

    #[Test]
    public function one_company_s_sweep_never_reaches_another_s_records(): void
    {
        $this->reviewDue(7);
        $this->watch(['lead_days' => [7]]);

        $other = $this->makeOrganization('Fenchurch Contracting');
        $otherController = $this->makePerson($other, 'Bola Adeyemi', null, [
            $this->makeRole($other, 'Controller', [
                'document.view' => 'organization',
                'document.create' => 'organization',
                'document_category.view' => 'organization',
            ]),
        ]);
        $otherCategory = $this->makeDocumentCategory($other, 'Procedures', ['requires_approval' => false]);
        $theirs = $this->makeDocument($other, $otherController, 'Their lifting plan', [
            'document_category_id' => $otherCategory->getKey(),
        ]);
        $this->withinOrganization($other, fn () => $theirs
            ->forceFill(['next_review_on' => now()->addDays(7)->toDateString()])->save());

        // Fenchurch has no watch at all, so nothing of theirs may be raised — but the assertion that
        // matters is the count: Meridian's sweep must find ONE record, its own, not two.
        $this->assertSame(1, $this->sweepEverything());

        // And the company with no watches is warned about nothing, including about Meridian's.
        $this->assertSame(0, $this->withinOrganization($other,
            fn () => app(ExpirySweeper::class)->sweepCurrentTenant()));
    }

    // ================================================================== §68 — told is not the same as allowed

    #[Test]
    public function being_named_on_a_watch_is_not_permission_to_be_told(): void
    {
        $this->reviewDue(7);

        // Sade is named explicitly, by an administrator, on purpose. She is in Piping and the
        // document is Civil's, so she cannot open it — and a warning naming its title would tell her
        // it exists, which is the leak §68 exists to close. The watch is configuration; the
        // dispatcher is the boundary, and configuration cannot argue with it.
        $this->watch([
            'lead_days' => [7],
            'notify_user_ids' => [$this->ngozi->getKey(), $this->sade->getKey()],
        ]);

        app(ActorProfile::class)->flush();

        $this->sweepEverything();

        $this->assertTrue($this->wasWarned($this->ngozi), 'the controller who can read the document was not warned');
        $this->assertFalse($this->wasWarned($this->sade), 'somebody who cannot open the document was told about it');
    }

    // ================================================================== §84 — nothing from a select box is trusted

    #[Test]
    public function the_platform_offers_only_the_dates_a_register_declared(): void
    {
        // The whole validation surface, and the reason it is asked of the catalogue rather than of
        // the schema: `created_at` is a real, queryable date column on documents, and it is not an
        // expiry. A watch on it would be a warning about every document ever filed.
        $this->assertTrue(ExpiryCatalogue::offers('document', 'next_review_on'));
        $this->assertFalse(ExpiryCatalogue::offers('document', 'created_at'));
        $this->assertFalse(ExpiryCatalogue::offers('document', 'retention_until'));

        // A register that never opted in offers nothing, whatever is posted at it.
        $this->assertFalse(ExpiryCatalogue::offers('project', 'end_date'));
        $this->assertSame([], ExpiryCatalogue::dateFieldsFor('project'));
        $this->assertSame([], ExpiryCatalogue::dateFieldsFor('not_a_register'));
    }

    #[Test]
    public function a_watch_pointed_at_a_date_the_platform_no_longer_offers_falls_silent(): void
    {
        $this->reviewDue(7);

        // Written past the service layer deliberately, standing in for a watch whose register was
        // renamed or retired years after it was configured. The sweep re-checks rather than trusting
        // the row, so the consequence is a watch that says nothing — not an exception at half past
        // six in the morning that takes every other company's warnings down with it.
        $watch = $this->watch(['lead_days' => [7]]);
        $this->withinOrganization($this->org, fn () => $watch->forceFill(['date_field' => 'created_at'])->save());

        $this->assertSame(0, $this->sweep($watch));
    }

    // ================================================================== it writes nothing

    #[Test]
    public function the_sweep_changes_no_record_it_warns_about(): void
    {
        $document = $this->reviewDue(7);
        $before = $this->withinOrganization($this->org, fn () => Document::query()
            ->whereKey($document->getKey())->firstOrFail());

        Event::fake([RecordExpiring::class]);
        $this->assertSame(1, $this->sweep($this->watch(['lead_days' => [7]])));

        $after = $this->withinOrganization($this->org, fn () => Document::query()
            ->whereKey($document->getKey())->firstOrFail());

        // No status moved, no flag set, no stamp written. A misconfigured cron must be able to cost
        // a company its REMINDERS and must never be able to make a lapsed certificate look valid —
        // which only holds while there is no state here to go stale.
        $this->assertSame($before->status_id, $after->status_id);
        $this->assertEquals($before->next_review_on, $after->next_review_on);
        $this->assertEquals($before->updated_at, $after->updated_at);
    }

    // ================================================================== helpers

    /**
     * One document in Civil, due for review in `$inDays` days. Negative is in the past.
     *
     * The date is force-filled rather than posted: what is under test is the sweep, and routing it
     * through the register's own validation would make every assertion here depend on the document
     * module's rules about review dates as well as on the engine's.
     */
    private function reviewDue(int $inDays): Document
    {
        $document = $this->document ??= $this->makeDocument($this->org, $this->ngozi, 'Lifting operations procedure', [
            'document_category_id' => $this->procedures->getKey(),
            'org_unit_id' => $this->civil->getKey(),
        ]);

        $this->withinOrganization($this->org, fn () => $document
            ->forceFill(['next_review_on' => now()->addDays($inDays)->toDateString()])
            ->saveQuietly());

        return $document;
    }

    private ?Document $document = null;

    /**
     * A watch on the document review date, replacing whatever this company had.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function watch(array $attributes = []): ExpiryWatch
    {
        return $this->withinOrganization($this->org, function () use ($attributes) {
            ExpiryWatch::query()->delete();

            return ExpiryWatch::create([
                'organization_id' => $this->org->getKey(),
                'entity_type' => 'document',
                'date_field' => 'next_review_on',
                'name' => 'Document due for review',
                'lead_days' => [30, 7, 1],
                'warn_when_overdue' => false,
                'notify_role_ids' => [],
                'notify_user_ids' => [],
                'notify_owner' => false,
                'notify_followers' => false,
                'is_active' => true,
                ...$attributes,
            ]);
        });
    }

    /** One watch, swept. */
    private function sweep(ExpiryWatch $watch): int
    {
        return $this->withinOrganization($this->org,
            fn () => app(ExpirySweeper::class)->sweepWatch($watch));
    }

    /** A whole nightly run for this company, as the scheduled command does it. */
    private function sweepEverything(): int
    {
        return $this->withinOrganization($this->org,
            fn () => app(ExpirySweeper::class)->sweepCurrentTenant());
    }

    private function moveDocumentTo(Document $document, string $statusKey): void
    {
        $this->withinOrganization($this->org, function () use ($document, $statusKey) {
            $status = Status::query()->forEntity('document')->where('key', $statusKey)->firstOrFail();

            $document->forceFill(['status_id' => $status->getKey()])->saveQuietly();
        });
    }

    private function wasWarned(User $person): bool
    {
        return Notification::query()->withoutGlobalScopes()
            ->where('user_id', $person->getKey())
            ->where('key', 'record.expiring')
            ->exists();
    }
}
