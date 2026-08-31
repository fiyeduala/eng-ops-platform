<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Submittal;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsEngineering;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Submittals — the package that went out for review, and the coded verdict that came back.
 *
 * The register exists to answer one question, usually months later and usually in an argument:
 * WHAT DID WE SEND, WHEN, AND WHAT DID THEY SAY? Every test here follows from that.
 *
 *   · THE CODE IS WRITTEN ONCE. `review()` is a one-way door, and after it the package is frozen —
 *     not discouraged from being edited, refused. A submittal whose contents can change after it
 *     was coded is a register that claims the reviewer approved something they never saw.
 *
 *   · THE NEXT ATTEMPT IS A NEW ROW. Not a reopened one. "We submitted this three times and they
 *     held it eleven weeks" is a claim that only exists if each attempt has its own number, its own
 *     dates and its own verdict, so the resubmittal chain is tested as data rather than as a button.
 *
 *   · NOBODY CODES THEIR OWN PACKAGE. Enforced by permission rather than by a self-check, because
 *     unlike a change request the reviewer is usually the client and the split is between companies
 *     rather than between people — so what is tested is that `submittal.review` is genuinely a
 *     separate authority from `submittal.edit` and `submittal.submit`.
 *
 *   · THE CODE CANNOT ARRIVE THROUGH THE FORM. `review_code` is on the protected list, so posting it
 *     to the ordinary update route must be ignored silently rather than accepted (§84). This is the
 *     single most valuable test in the file: it is the shortcut a real attacker takes, and no
 *     amount of hiding the field in the Blade would stop it.
 */
final class SubmittalReviewTest extends TestCase
{
    use BuildsEngineering, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private Project $project;

    private User $ifeanyi;  // engineer: prepares and sends packages, may not code them

    private User $helen;    // reviewer: codes them, may not assemble them

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);

        $submitter = $this->makeRole($this->org, 'Design Engineer', [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'document.view' => 'organization',
            'submittal.view' => 'organization',
            'submittal.create' => 'organization',
            'submittal.edit' => 'organization',
            'submittal.delete' => 'organization',
            'submittal.submit' => 'organization',
        ]);

        // Everything the submitter has, plus the one authority that matters, minus the ones that
        // would let them quietly change what they are about to approve.
        $reviewer = $this->makeRole($this->org, 'Client Representative', [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'submittal.view' => 'organization',
            'submittal.review' => 'organization',
        ]);

        $this->ifeanyi = $this->makePerson($this->org, 'Ifeanyi Obi', $this->civil, [$submitter]);
        $this->helen = $this->makePerson($this->org, 'Helen Marsh', $this->civil, [$reviewer]);

        $this->project = $this->makeProject($this->org, $this->ifeanyi, 'Terminal Expansion');
    }

    // ================================================================== sending

    #[Test]
    public function an_empty_package_cannot_be_sent(): void
    {
        $submittal = $this->makeSubmittal($this->org, $this->ifeanyi, 'Rebar certificates', [
            'project_id' => $this->project->id,
        ]);

        // A submittal with nothing on it is not a submittal; it is a covering letter. Letting one go
        // out starts a review clock against a package the reviewer cannot possibly act on.
        $this->actingFresh($this->ifeanyi)
            ->post(route('submittals.submit', $submittal))
            ->assertSessionHasErrors();

        $this->assertNull($submittal->refresh()->submitted_on);
    }

    #[Test]
    public function sending_stamps_the_date_and_the_person_who_sent_it(): void
    {
        $submittal = $this->package();

        $this->actingFresh($this->ifeanyi)
            ->post(route('submittals.submit', $submittal))
            ->assertSessionHasNoErrors();

        $submittal->refresh();

        // Stamped at the moment it went, not at the moment it was created. A package may sit in
        // draft for a week, and every "how long have they held this" figure in the register counts
        // from the date it actually left.
        $this->assertNotNull($submittal->submitted_on);
        $this->assertSame((int) $this->ifeanyi->id, (int) $submittal->submitted_by);
        $this->assertFalse($submittal->isReviewed());
    }

    // ================================================================== the verdict

    #[Test]
    public function the_reviewer_records_a_code_and_a_status_and_the_two_are_not_derived_from_each_other(): void
    {
        $submittal = $this->sent();

        // Code B against status `revise_resubmit` — a combination that only makes sense because the
        // application never infers one from the other. On one contract "B" is approved-as-noted and
        // on the next it is revise-and-resubmit, and a system that guessed would be silently wrong
        // on half its clients.
        $this->actingFresh($this->helen)
            ->post(route('submittals.review', $submittal), [
                'review_code' => 'b_approved_as_noted',
                'status_key' => 'revise_resubmit',
                'review_comment' => 'Confirm the bend radii on sheet 3.',
            ])->assertSessionHasNoErrors();

        $submittal->refresh();

        $this->assertTrue($submittal->isReviewed());
        $this->assertSame('b_approved_as_noted', $submittal->review_code);
        $this->assertSame((int) $this->helen->id, (int) $submittal->reviewed_by);
        $this->assertSame('revise_resubmit', $submittal->status?->key);
    }

    #[Test]
    public function the_person_who_assembled_the_package_cannot_code_it(): void
    {
        $submittal = $this->sent();

        // Ifeanyi holds create, edit, delete and submit on this register. He does not hold review,
        // and there is no combination of the others that adds up to it.
        $this->actingFresh($this->ifeanyi)
            ->post(route('submittals.review', $submittal), [
                'review_code' => 'a_approved',
                'status_key' => 'approved',
            ])->assertForbidden();

        $this->assertFalse($submittal->refresh()->isReviewed());
    }

    #[Test]
    public function a_code_posted_to_the_ordinary_edit_route_is_ignored(): void
    {
        $submittal = $this->sent();

        // The most important test in this file. The review form is hidden from Ifeanyi, but hiding
        // is presentation; this is the enforcement. He posts a valid edit with a review verdict
        // smuggled into it, and the service's protected list drops the verdict on the floor while
        // accepting the legitimate part of the edit (§84).
        $this->actingFresh($this->ifeanyi)
            ->put(route('submittals.update', $submittal), [
                'title' => 'Rebar certificates (rev 2)',
                'type' => 'material',
                'project_id' => $this->project->id,
                'confidentiality' => 'internal',
                'review_code' => 'a_approved',
                'reviewed_by' => $this->ifeanyi->id,
                'reviewed_at' => now()->toDateTimeString(),
            ])->assertSessionHasNoErrors();

        $submittal->refresh();

        $this->assertSame('Rebar certificates (rev 2)', $submittal->title);
        $this->assertNull($submittal->review_code);
        $this->assertNull($submittal->reviewed_at);
        $this->assertFalse($submittal->isReviewed());
    }

    #[Test]
    public function a_coded_package_is_frozen(): void
    {
        $submittal = $this->coded();

        // Not "discouraged from being edited" — refused, by the policy, on every route that could
        // change what the register says was sent.
        //
        // The body is deliberately COMPLETE and valid. A form request authorizes at the request
        // level and validates before the controller's policy call runs, so a half-filled payload
        // would come back 302-with-validation-errors and this test would pass without ever reaching
        // the rule it exists to prove.
        $this->actingFresh($this->ifeanyi)
            ->put(route('submittals.update', $submittal), [
                'title' => 'Something else',
                'type' => 'material',
                'project_id' => $this->project->id,
                'confidentiality' => 'internal',
            ])
            ->assertForbidden();

        $this->actingFresh($this->ifeanyi)
            ->delete(route('submittals.destroy', $submittal))
            ->assertForbidden();

        $this->actingFresh($this->ifeanyi)
            ->post(route('submittals.items.store', $submittal), ['description' => 'A late addition'])
            ->assertForbidden();

        $submittal->refresh();

        $this->assertSame('Rebar certificates', $submittal->title);
        $this->assertSame(1, $submittal->items()->count());
    }

    #[Test]
    public function a_second_verdict_is_refused(): void
    {
        $submittal = $this->coded();

        $this->actingFresh($this->helen)
            ->post(route('submittals.review', $submittal), [
                'review_code' => 'a_approved',
                'status_key' => 'approved',
            ])->assertForbidden();

        // Still the first answer, unchanged. A code that can be revised in place is a code nobody
        // can rely on in a dispute.
        $this->assertSame('c_revise_and_resubmit', $submittal->refresh()->review_code);
    }

    #[Test]
    public function a_status_key_from_another_register_is_rejected(): void
    {
        $submittal = $this->sent();

        // `answered` belongs to the RFI vocabulary. Validation is against this entity type's own
        // statuses, so a plausible-looking key from elsewhere in the application does not land the
        // submittal in a state its register has no name for (§84).
        $this->actingFresh($this->helen)
            ->post(route('submittals.review', $submittal), [
                'review_code' => 'a_approved',
                'status_key' => 'answered',
            ])->assertSessionHasErrors('status_key');

        $this->assertFalse($submittal->refresh()->isReviewed());
    }

    #[Test]
    public function a_review_code_outside_the_configured_list_is_rejected(): void
    {
        $submittal = $this->sent();

        $this->actingFresh($this->helen)
            ->post(route('submittals.review', $submittal), [
                'review_code' => 'z_approved_by_me_personally',
                'status_key' => 'approved',
            ])->assertSessionHasErrors('review_code');

        $this->assertNull($submittal->refresh()->review_code);
    }

    // ================================================================== the next attempt

    #[Test]
    public function the_next_attempt_is_a_new_record_that_remembers_its_parent(): void
    {
        $first = $this->coded();

        $this->actingFresh($this->ifeanyi)
            ->post(route('submittals.resubmit', $first))
            ->assertRedirect();

        $second = Submittal::query()->where('resubmittal_of_id', $first->id)->firstOrFail();

        // Its own number and its own row, pointing back. The chain is the whole argument about how
        // long the review actually took.
        $this->assertNotSame($first->number, $second->number);
        $this->assertSame(2, $second->attemptNumber());
        $this->assertSame($first->title, $second->title);

        // And a fresh attempt carries no verdict. Copying last time's code forward would have the
        // register claim a review that has not happened.
        $this->assertNull($second->review_code);
        $this->assertNull($second->reviewed_at);
        $this->assertFalse($second->isReviewed());

        // The first attempt is untouched. Nothing about opening attempt two rewrites attempt one.
        $this->assertSame('c_revise_and_resubmit', $first->refresh()->review_code);
    }

    #[Test]
    public function the_contents_come_forward_without_their_codes(): void
    {
        $first = $this->coded();

        $item = $first->items()->first();
        $item->forceFill(['review_code' => 'd_rejected'])->save();

        $this->actingFresh($this->ifeanyi)->post(route('submittals.resubmit', $first));

        $second = Submittal::query()->where('resubmittal_of_id', $first->id)->firstOrFail();
        $copied = $second->items()->firstOrFail();

        // Thirty line items retyped by hand is how a register stops being used, so they are copied.
        // Their verdicts are not, for the same reason the parent's is not.
        $this->assertSame($item->description, $copied->description);
        $this->assertSame($item->reference, $copied->reference);
        $this->assertNull($copied->review_code);
        $this->assertNotSame((int) $item->id, (int) $copied->id);
    }

    #[Test]
    public function a_package_that_has_not_been_reviewed_cannot_be_resubmitted(): void
    {
        $submittal = $this->sent();

        // There is nothing to resubmit against. Allowing it would produce two live attempts at the
        // same package and no way to say which one the reviewer is holding.
        $this->actingFresh($this->ifeanyi)
            ->post(route('submittals.resubmit', $submittal))
            ->assertForbidden();

        $this->assertSame(1, Submittal::query()->count());
    }

    // ================================================================== fixtures

    /** A draft with one line on it — the least that can legitimately be sent. */
    private function package(): Submittal
    {
        return $this->makeSubmittalWithItem($this->org, $this->ifeanyi, 'Rebar certificates', [
            'project_id' => $this->project->id,
            'party_name' => 'Harbour Authority',
        ]);
    }

    /** The same package, out for review. */
    private function sent(): Submittal
    {
        $submittal = $this->package();

        $this->actingFresh($this->ifeanyi)
            ->post(route('submittals.submit', $submittal))
            ->assertSessionHasNoErrors();

        return $submittal->refresh();
    }

    /** The same package, come back coded C — the case that provokes a resubmittal. */
    private function coded(): Submittal
    {
        $submittal = $this->sent();

        $this->actingFresh($this->helen)
            ->post(route('submittals.review', $submittal), [
                'review_code' => 'c_revise_and_resubmit',
                'status_key' => 'revise_resubmit',
                'review_comment' => 'Mill certificates do not match the heat numbers.',
            ])->assertSessionHasNoErrors();

        return $submittal->refresh();
    }
}
