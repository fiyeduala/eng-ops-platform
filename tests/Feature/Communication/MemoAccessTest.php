<?php

declare(strict_types=1);

namespace Tests\Feature\Communication;

use App\Models\Memo;
use App\Models\MemoRecipient;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Status;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Memos: the first module where PUBLICATION is an access boundary (Â§17, Â§59, Â§84).
 *
 * Two rules are being defended here, and neither is expressible as CRUD.
 *
 *   THE DRAFT CURTAIN. `issued_at` is a scope predicate, not a display flag. A half-written memo is
 *   reachable by its author and by the people who decide memos go out â€” and by nobody else, however
 *   broad their reach. The director test below is the one that matters: organization-wide
 *   `memo.view` is the widest grant the system offers and it still does not open a draft.
 *
 *   THE RECORD IS WHAT WAS SENT. Once issued, a memo cannot be edited or deleted by anyone at any
 *   scope, and a name cannot be taken off the distribution list. Adding a late recipient IS allowed,
 *   because that grants rather than falsifies. The asymmetry is the point, and it is the thing a
 *   future refactor is most likely to flatten.
 *
 * The fixture is one company, two departments, and four people whose reach comes from four
 * different places:
 *
 *   Civil (dept)        Femi   â€” writes memos: `own` reach plus `memo.issue`
 *   Civil (dept)        Chidi  â€” engineer: `memo.view:assigned` and nothing else
 *   Executive (dept)    Aisha  â€” director: `memo.view:organization`, and NO `memo.issue`
 *   Commercial (dept)   Bola   â€” `memo.view:department` over Commercial only
 *
 * Break the curtain and Aisha's tests fail. Break the assigned axis and Chidi's do. Break the ACL
 * that carries a confidential memo to its recipients and only Bola's does.
 */
final class MemoAccessTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private OrgUnit $commercial;

    private User $femi;      // author, may issue

    private User $chidi;     // recipient, assigned scope only

    private User $aisha;     // organization-wide reach, may not issue

    private User $bola;      // another department

    private User $tunde;     // another department, but reachable by being addressed

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');

        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);
        $this->commercial = $this->makeUnit($this->org, 'Commercial', OrgUnitType::Department);
        $executive = $this->makeUnit($this->org, 'Executive', OrgUnitType::Department);

        // The author. Everything at `own`, so his reach comes from having written the memo and
        // nothing else â€” which keeps the issued-memo refusals below honest: they cannot be passing
        // for the duller reason that he was short of a permission.
        $authorRole = $this->makeRole($this->org, 'Contracts Manager', [
            'memo.view' => ['own', 'assigned'],
            'memo.create' => 'own',
            'memo.edit' => 'own',
            'memo.delete' => 'own',
            'memo.issue' => 'own',
            'user.view' => 'organization',
        ]);

        $engineerRole = $this->makeRole($this->org, 'Engineer', [
            'memo.view' => 'assigned',
        ]);

        // The widest read grant in the system, and deliberately no `memo.issue`.
        $directorRole = $this->makeRole($this->org, 'Director', [
            'memo.view' => 'organization',
        ]);

        $commercialRole = $this->makeRole($this->org, 'Commercial Lead', [
            'memo.view' => 'department',
        ]);

        $this->femi = $this->makePerson($this->org, 'Femi Balogun', $this->civil, [$authorRole]);
        $this->chidi = $this->makePerson($this->org, 'Chidi Nwosu', $this->civil, [$engineerRole]);
        $this->aisha = $this->makePerson($this->org, 'Aisha Bello', $executive, [$directorRole]);
        $this->bola = $this->makePerson($this->org, 'Bola Adeniyi', $this->commercial, [$commercialRole]);
        $this->tunde = $this->makePerson($this->org, 'Tunde Okafor', $this->commercial, [$engineerRole]);
    }

    // ------------------------------------------------------------------ the draft curtain

    #[Test]
    public function a_draft_is_reachable_by_its_author_and_by_nobody_else(): void
    {
        $memo = $this->draftToChidi();

        $this->actingFresh($this->femi)->get(route('memos.show', $memo))->assertOk();

        // Addressed on it, and still cannot read it: the distribution list is not delivery.
        $this->actingFresh($this->chidi)->get(route('memos.show', $memo))->assertForbidden();

        // Organization-wide reach. The curtain is not about breadth.
        $this->actingFresh($this->aisha)->get(route('memos.show', $memo))->assertForbidden();
    }

    #[Test]
    public function a_draft_does_not_appear_on_a_listing_it_would_otherwise_match(): void
    {
        $memo = $this->draftToChidi();

        $this->actingFresh($this->aisha)
            ->get(route('memos.index', ['view' => 'all']))
            ->assertOk()
            ->assertDontSee($memo->number);

        $this->actingFresh($this->femi)
            ->get(route('memos.index', ['view' => 'drafts']))
            ->assertOk()
            ->assertSee($memo->number);
    }

    #[Test]
    public function issuing_is_what_hands_the_recipients_sight_of_it(): void
    {
        $memo = $this->draftToChidi();

        $this->actingFresh($this->chidi)->get(route('memos.show', $memo))->assertForbidden();

        $memo = $this->issueMemo($this->org, $memo, $this->femi);

        $this->actingFresh($this->chidi)->get(route('memos.show', $memo))->assertOk();
        $this->actingFresh($this->aisha)->get(route('memos.show', $memo))->assertOk();
    }

    #[Test]
    public function being_addressed_is_the_only_reach_a_recipient_gets(): void
    {
        $mine = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        $somebody_elses = $this->makeMemo($this->org, $this->femi, 'Payment certificate 12', [
            'org_unit_id' => $this->civil->id,
        ], [$this->bola]);

        $somebody_elses = $this->issueMemo($this->org, $somebody_elses, $this->femi);

        $this->actingFresh($this->chidi)->get(route('memos.show', $mine))->assertOk();
        $this->actingFresh($this->chidi)->get(route('memos.show', $somebody_elses))->assertForbidden();
    }

    #[Test]
    public function a_department_scope_does_not_reach_another_departments_memo(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        // Filed against Civil, and Bola's department scope covers Commercial.
        $this->actingFresh($this->bola)->get(route('memos.show', $memo))->assertForbidden();
    }

    // ------------------------------------------------------------------ the record is what was sent

    #[Test]
    public function an_issued_memo_cannot_be_edited_by_anybody_including_its_author(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        $this->actingFresh($this->femi)->get(route('memos.edit', $memo))->assertForbidden();

        // And not through the endpoint either â€” hiding the form is a courtesy, the route is the
        // control (Â§84).
        $this->actingFresh($this->femi)
            ->put(route('memos.update', $memo), [
                'subject' => 'Quietly rewritten after the fact',
                'body' => 'Different from what everybody received.',
                'confidentiality' => Confidentiality::Internal->value,
            ])
            ->assertForbidden();

        $this->assertSame(
            'Revised setting-out for the eastern abutment',
            $this->withinOrganization($this->org, fn () => Memo::findOrFail($memo->id))->subject,
        );
    }

    #[Test]
    public function an_issued_memo_cannot_be_deleted(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        $this->actingFresh($this->femi)->delete(route('memos.destroy', $memo))->assertForbidden();

        $this->assertNotNull($this->withinOrganization($this->org, fn () => Memo::find($memo->id)));
    }

    #[Test]
    public function a_draft_can_be_deleted_because_nobody_has_seen_it(): void
    {
        $memo = $this->draftToChidi();

        $this->actingFresh($this->femi)->delete(route('memos.destroy', $memo))->assertRedirect();

        $this->assertNull($this->withinOrganization($this->org, fn () => Memo::find($memo->id)));
    }

    /**
     * The regression this file exists for.
     *
     * `MemoPolicy::unaddress()` used to delegate to check(update), which reads as though it would
     * inherit the class's issued-memo refusal. It does not: check() is the five gates and the
     * `memo.edit` permission, and it never calls the overridden update(). The result was that a
     * name could be quietly removed from the record of who was told.
     */
    #[Test]
    public function a_recipient_cannot_be_taken_off_an_issued_memo(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);
        $row = $this->receipt($memo, $this->chidi);

        $this->actingFresh($this->femi)
            ->delete(route('memos.recipients.destroy', [$memo, $row]))
            ->assertForbidden();

        $this->assertNotNull($this->withinOrganization($this->org, fn () => MemoRecipient::find($row->id)));
    }

    #[Test]
    public function a_recipient_can_be_taken_off_a_draft(): void
    {
        $memo = $this->draftToChidi();
        $row = $this->receipt($memo, $this->chidi);

        $this->actingFresh($this->femi)
            ->delete(route('memos.recipients.destroy', [$memo, $row]))
            ->assertRedirect();

        $this->assertNull($this->withinOrganization($this->org, fn () => MemoRecipient::find($row->id)));
    }

    #[Test]
    public function a_late_recipient_can_still_be_added_to_an_issued_memo_and_can_then_read_it(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        // Tunde sits in Commercial and reaches nothing in Civil. His `memo.view` is granted at
        // `assigned`, so the distribution list is his entire route to this document.
        $this->actingFresh($this->tunde)->get(route('memos.show', $memo))->assertForbidden();

        $this->actingFresh($this->femi)
            ->post(route('memos.recipients.store', $memo), [
                'recipients' => [$this->tunde->id],
                'kind' => 'to',
            ])
            ->assertRedirect();

        $this->actingFresh($this->tunde)->get(route('memos.show', $memo))->assertOk();
    }

    /**
     * The rule an administrator will trip over, pinned down so that it is a decision rather than an
     * accident.
     *
     * Being on the distribution list of a PUBLIC or INTERNAL memo grants nothing by itself. It makes
     * the memo reachable through the `assigned` axis, and that axis only opens for a role that was
     * granted `memo.view` at `assigned` — Bola's is granted at `department`, so an internal memo
     * filed against another department stays shut even with his name on it.
     *
     * MemoService writes no ACL row for these, deliberately: a row per recipient per routine memo
     * would fill the table the access engine consults on every record check. Restricted and
     * confidential memos DO take that route, which is the test immediately below.
     */
    #[Test]
    public function addressing_an_internal_memo_does_not_manufacture_reach_a_role_was_never_given(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        $this->actingFresh($this->femi)
            ->post(route('memos.recipients.store', $memo), [
                'recipients' => [$this->bola->id],
                'kind' => 'to',
            ])
            ->assertRedirect();

        $this->assertNotNull($this->receipt($memo, $this->bola), 'he is on the list');

        $this->actingFresh($this->bola)->get(route('memos.show', $memo))->assertForbidden();
    }

    #[Test]
    public function a_recipient_row_cannot_be_reached_through_another_memos_url(): void
    {
        $mine = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        $other = $this->makeMemo($this->org, $this->femi, 'Site instruction 04', [
            'org_unit_id' => $this->civil->id,
        ], [$this->bola]);

        $row = $this->receipt($other, $this->bola);

        // Scoped bindings resolve `{recipient}` through the memo in the URL, so this is a 404 at
        // the router rather than a delete against somebody else's document (Â§94).
        $this->actingFresh($this->femi)
            ->delete(route('memos.recipients.destroy', [$mine->id, $row->id]))
            ->assertNotFound();

        $this->assertNotNull($this->withinOrganization($this->org, fn () => MemoRecipient::find($row->id)));
    }

    // ------------------------------------------------------------------ confidentiality

    #[Test]
    public function a_confidential_memo_reaches_its_recipients_by_explicit_grant_and_nobody_else(): void
    {
        $memo = $this->makeMemo($this->org, $this->femi, 'Commercial position on the claim', [
            'org_unit_id' => $this->civil->id,
            'confidentiality' => Confidentiality::Confidential,
        ], [$this->bola]);

        $memo = $this->issueMemo($this->org, $memo, $this->femi);

        // Bola is in another department entirely and has no confidentiality permission. He reaches
        // it solely through the record_permissions row MemoService writes on issue.
        $this->actingFresh($this->bola)->get(route('memos.show', $memo))->assertOk();

        // Aisha reads every ordinary memo in the company and does not read this one. Nothing about
        // breadth lifts confidentiality â€” only an explicit grant does.
        $this->actingFresh($this->aisha)->get(route('memos.show', $memo))->assertForbidden();
    }

    // ------------------------------------------------------------------ issuing

    #[Test]
    public function issuing_needs_the_issue_permission_rather_than_the_edit_permission(): void
    {
        $memo = $this->draftToChidi();
        $issued = $this->statusFor('issued');

        // Aisha may read everything and issue nothing. Refused at the route, before any policy
        // question about this particular memo.
        $this->actingFresh($this->aisha)
            ->post(route('memos.status', $memo), ['status_id' => $issued->id])
            ->assertForbidden();

        $this->assertNull($this->withinOrganization($this->org, fn () => Memo::findOrFail($memo->id))->issued_at);
    }

    #[Test]
    public function a_memo_addressed_to_nobody_cannot_be_issued(): void
    {
        $memo = $this->makeMemo($this->org, $this->femi, 'Draft with no distribution', [
            'org_unit_id' => $this->civil->id,
        ]);

        $this->actingFresh($this->femi)
            ->from(route('memos.show', $memo))
            ->post(route('memos.status', $memo), ['status_id' => $this->statusFor('issued')->id])
            ->assertRedirect(route('memos.show', $memo))
            ->assertSessionHasErrors('status_id');

        $this->assertNull($this->withinOrganization($this->org, fn () => Memo::findOrFail($memo->id))->issued_at);
    }

    #[Test]
    public function withdrawing_says_so_rather_than_removing_the_memo(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        $this->actingFresh($this->femi)
            ->post(route('memos.status', $memo), [
                'status_id' => $this->statusFor('withdrawn')->id,
                'comment' => 'Superseded by the revised drawing issued the same day.',
            ])
            ->assertRedirect();

        $fresh = $this->withinOrganization($this->org, fn () => Memo::findOrFail($memo->id));

        $this->assertSame('withdrawn', $fresh->statusKey());
        $this->assertNotNull($fresh->issued_at, 'withdrawing must not erase the fact that it went out');
        $this->assertStringContainsString('Superseded', (string) $fresh->withdrawn_reason);

        // Still readable by the person who received it. That is the whole difference between a
        // withdrawal and a delete.
        $this->actingFresh($this->chidi)->get(route('memos.show', $fresh))->assertOk();
    }

    // ------------------------------------------------------------------ receipts and search

    #[Test]
    public function opening_an_issued_memo_stamps_the_read_receipt_once(): void
    {
        $memo = $this->issueMemo($this->org, $this->draftToChidi(), $this->femi);

        $this->assertNull($this->receipt($memo, $this->chidi)->read_at);

        $this->actingFresh($this->chidi)->get(route('memos.show', $memo))->assertOk();

        $first = $this->receipt($memo, $this->chidi)->read_at;
        $this->assertNotNull($first, 'opening the memo is what "read" means');

        // First read wins: re-opening must not turn the receipt into a last-seen tracker.
        $this->travel(5)->minutes();
        $this->actingFresh($this->chidi)->get(route('memos.show', $memo))->assertOk();

        $this->assertEquals($first, $this->receipt($memo, $this->chidi)->read_at);
    }

    #[Test]
    public function the_search_box_matches_the_subject_but_never_the_body(): void
    {
        $memo = $this->makeMemo($this->org, $this->femi, 'Temporary works proposal', [
            'org_unit_id' => $this->civil->id,
            'body' => 'The propped cantilever arrangement is unacceptable to the checking engineer.',
        ], [$this->chidi]);

        $memo = $this->issueMemo($this->org, $memo, $this->femi);

        $this->actingFresh($this->chidi)
            ->get(route('memos.index', ['view' => 'all', 'q' => 'Temporary works']))
            ->assertOk()
            ->assertSee($memo->number);

        // A phrase that appears only in the body must not report the document's existence, and
        // must not report its absence either â€” the result is simply empty (Â§61).
        $this->actingFresh($this->chidi)
            ->get(route('memos.index', ['view' => 'all', 'q' => 'propped cantilever']))
            ->assertOk()
            ->assertDontSee($memo->number);
    }

    // ------------------------------------------------------------------ tenancy

    #[Test]
    public function another_organization_gets_a_404_rather_than_a_refusal(): void
    {
        $other = $this->makeOrganization('Kestrel Infrastructure');
        $unit = $this->makeUnit($other, 'Engineering', OrgUnitType::Department);

        $role = $this->makeRole($other, 'Contracts Manager', [
            'memo.view' => 'organization',
            'memo.create' => 'organization',
            'memo.edit' => 'organization',
            'memo.issue' => 'organization',
            'user.view' => 'organization',
        ]);

        $stranger = $this->makePerson($other, 'Ruth Iwu', $unit, [$role]);
        $theirs = $this->issueMemo(
            $other,
            $this->makeMemo($other, $stranger, 'Their internal memo', ['org_unit_id' => $unit->id], [$stranger]),
            $stranger,
        );

        // 404, not 403. A refusal would confirm that the id exists in another company's data (Â§75).
        $this->actingFresh($this->femi)->get(route('memos.show', $theirs->id))->assertNotFound();
        $this->actingFresh($stranger)->get(route('memos.show', $this->draftToChidi()->id))->assertNotFound();
    }

    // ------------------------------------------------------------------ fixture helpers

    private function draftToChidi(): Memo
    {
        return $this->makeMemo($this->org, $this->femi, 'Revised setting-out for the eastern abutment', [
            'org_unit_id' => $this->civil->id,
        ], [$this->chidi]);
    }

    private function receipt(Memo $memo, User $user): MemoRecipient
    {
        return $this->withinOrganization($this->org, fn () => MemoRecipient::query()
            ->where('memo_id', $memo->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail());
    }

    private function statusFor(string $key): Status
    {
        return $this->withinOrganization($this->org, fn () => Status::query()
            ->forEntity('memo')
            ->where('key', $key)
            ->firstOrFail());
    }
}
