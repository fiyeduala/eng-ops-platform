<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Models\ChangeRequest;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\User;
use App\Support\Enums\OrgUnitType;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsEngineering;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Change requests — the register where the money starts.
 *
 * A change request is the first record in the application that carries a figure somebody will later
 * be paid on, and that changes what the tests have to be about. The rest of the engineering
 * registers protect a fact; this one protects a DECISION, and a decision is only worth anything if
 * three things are true about it:
 *
 *   · NOBODY DECIDES THEIR OWN. Not at any scope, not with any permission, not as an administrator.
 *     `ChangeRequestPolicy::decide()` refuses the author before it consults a single grant, and it
 *     checks BOTH `created_by` and `raised_by` — because the obvious way round a check on one of
 *     them is to put somebody else's name in the other. This is the one rule in the module that
 *     configuration cannot relax, and the tests below say so in both directions (§10, §84).
 *
 *   · IT IS RECORDED ONCE. A second decision is refused, so a rejection cannot quietly become an
 *     approval on a Friday afternoon. If the answer was wrong, the honest instrument is a new
 *     change request, which is also the one that leaves a trail.
 *
 *   · THE FIGURE IS FROZEN WITH IT. `update` is refused once a request is decided, which means the
 *     decision is the LAST moment the cost and schedule impact can move — so they are accepted as
 *     part of deciding, and the tests pin down both that they stick and that passing nothing does
 *     not blank what was already there.
 *
 * And one rule that is about honesty rather than about authority: anything other than an approval
 * must say why. "Rejected", with no note, is the entry that becomes an argument nobody can settle
 * once the person who wrote it has left.
 */
final class ChangeRequestDecisionTest extends TestCase
{
    use BuildsEngineering, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $civil;

    private Project $project;

    private User $chidi;    // engineer: raises changes, cannot decide anything

    private User $grace;    // project director: decides changes, including her own — she may not

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = $this->makeOrganization('Meridian Engineering');
        $engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Division);
        $this->civil = $this->makeUnit($this->org, 'Civil', OrgUnitType::Department, $engineering);

        $originator = $this->makeRole($this->org, 'Project Engineer', [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'change_request.view' => 'organization',
            'change_request.create' => 'organization',
            'change_request.edit' => 'organization',
            'change_request.delete' => 'organization',
            'change_request.submit' => 'organization',
        ]);

        // Everything the originator has plus the two authorities that matter — which is exactly the
        // shape that makes the self-decision rule necessary. Grace can raise a change and she can
        // approve changes; the only thing standing between those two facts is the policy.
        $director = $this->makeRole($this->org, 'Project Director', [
            'org_unit.view' => 'organization',
            'project.view' => 'organization',
            'change_request.view' => 'organization',
            'change_request.create' => 'organization',
            'change_request.edit' => 'organization',
            'change_request.submit' => 'organization',
            'change_request.approve' => 'organization',
            'change_request.review' => 'organization',
        ]);

        $this->chidi = $this->makePerson($this->org, 'Chidi Nwachukwu', $this->civil, [$originator]);
        $this->grace = $this->makePerson($this->org, 'Grace Bello', $this->civil, [$director]);

        $this->project = $this->makeProject($this->org, $this->grace, 'Terminal Expansion');
    }

    // ================================================================== separation of duties

    #[Test]
    public function nobody_decides_a_change_they_created(): void
    {
        $change = $this->raisedBy($this->grace);

        // Grace holds `change_request.approve` at organization scope. The refusal has nothing to do
        // with reach: it is that this is her own request, and the policy checks that first.
        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'approved',
            ])->assertForbidden();

        $this->assertFalse($change->refresh()->isDecided());
    }

    #[Test]
    public function nor_one_they_are_merely_named_as_having_raised(): void
    {
        // Created by Chidi, raised in Grace's name. The obvious way round a check on `created_by`
        // alone, which is why the policy checks both columns.
        $change = $this->raisedBy($this->chidi, ['raised_by' => $this->grace->id]);

        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'approved',
            ])->assertForbidden();

        $this->assertFalse($change->refresh()->isDecided());
    }

    #[Test]
    public function somebody_elses_change_is_decided_normally(): void
    {
        $change = $this->raisedBy($this->chidi);

        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'approved',
                'decision_notes' => 'Agreed at the site meeting of the 12th.',
            ])->assertSessionHasNoErrors();

        $change->refresh();

        $this->assertTrue($change->isDecided());
        $this->assertSame('approved', $change->disposition?->value);
        $this->assertSame((int) $this->grace->id, (int) $change->decided_by);
        $this->assertNotNull($change->decided_at);
    }

    #[Test]
    public function raising_a_change_does_not_by_itself_let_you_decide_one(): void
    {
        $change = $this->raisedBy($this->grace);

        // Chidi has create, edit, delete and submit. None of them adds up to approve.
        $this->actingFresh($this->chidi)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'rejected',
                'decision_notes' => 'No.',
            ])->assertForbidden();

        $this->assertFalse($change->refresh()->isDecided());
    }

    // ================================================================== the decision itself

    #[Test]
    public function anything_that_is_not_an_approval_must_say_why(): void
    {
        $change = $this->raisedBy($this->chidi);

        // A bare "rejected" is the entry that turns into an argument two years later with nobody
        // left who can explain it. An approval needs no justification for the same reason a green
        // light needs none — the request itself is the record of what was agreed.
        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'rejected',
            ])->assertSessionHasErrors('disposition');

        $this->assertFalse($change->refresh()->isDecided());
    }

    #[Test]
    public function a_decision_is_recorded_once(): void
    {
        $change = $this->decided($this->raisedBy($this->chidi), 'rejected', 'Outside the agreed scope.');

        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'approved',
                'decision_notes' => 'On reflection.',
            ])->assertForbidden();

        // Still rejected. A register whose decisions can be replaced in place cannot support a
        // claim, which is the only reason a company keeps one.
        $this->assertSame('rejected', $change->refresh()->disposition?->value);
    }

    #[Test]
    public function a_decided_change_is_frozen_against_editing_and_deletion(): void
    {
        $change = $this->decided($this->raisedBy($this->chidi), 'approved');

        // Full, valid bodies on purpose: a form request validates before the controller reaches the
        // policy, so a half-filled payload would fail on validation and never test the rule.
        $this->actingFresh($this->chidi)
            ->put(route('change-requests.update', $change), [
                'title' => 'Additional piling — revised',
                'description' => 'Rewritten after the fact.',
                'project_id' => $this->project->id,
                'origin' => 'site',
                'confidentiality' => 'internal',
            ])->assertForbidden();

        $this->actingFresh($this->chidi)
            ->delete(route('change-requests.destroy', $change))
            ->assertForbidden();

        $this->assertSame('Additional piling', $change->refresh()->title);
    }

    #[Test]
    public function a_disposition_posted_to_the_ordinary_edit_route_is_ignored(): void
    {
        $change = $this->raisedBy($this->chidi);

        // The decide form is hidden from Chidi. Hiding is presentation; this is the enforcement —
        // he posts a legitimate edit with a decision smuggled into it, and the service's protected
        // list drops the decision while accepting the edit (§84).
        $this->actingFresh($this->chidi)
            ->put(route('change-requests.update', $change), [
                'title' => 'Additional piling',
                'description' => 'Ten additional piles to the north abutment.',
                'project_id' => $this->project->id,
                'origin' => 'site',
                'confidentiality' => 'internal',
                'disposition' => 'approved',
                'decided_by' => $this->chidi->id,
                'decided_at' => now()->toDateTimeString(),
                'decision_notes' => 'Approved by me.',
            ])->assertSessionHasNoErrors();

        $change->refresh();

        $this->assertNull($change->disposition);
        $this->assertNull($change->decided_at);
        $this->assertNull($change->decided_by);
        $this->assertFalse($change->isDecided());
    }

    // ================================================================== the figures

    #[Test]
    public function the_impact_figures_may_be_set_at_the_moment_of_decision(): void
    {
        $change = $this->raisedBy($this->chidi);

        $this->assertNull($change->cost_impact);

        // In practice the estimator prices the change AS PART OF deciding it. Since `update` closes
        // the instant the decision lands, this is the last moment the figures can move — so they
        // travel with the decision rather than depending on an edit that might never happen.
        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'approved',
                'decision_notes' => 'Priced at the meeting.',
                'cost_impact' => '48250.00',
                'schedule_impact_days' => 14,
            ])->assertSessionHasNoErrors();

        $change->refresh();

        $this->assertSame('48250.00', (string) $change->cost_impact);
        $this->assertSame(14, (int) $change->schedule_impact_days);
    }

    #[Test]
    public function deciding_without_figures_does_not_blank_the_ones_already_there(): void
    {
        $change = $this->raisedBy($this->chidi, [
            'cost_impact' => 12000,
            'schedule_impact_days' => 5,
        ]);

        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'deferred',
                'decision_notes' => 'Revisit after the piling results.',
            ])->assertSessionHasNoErrors();

        $change->refresh();

        // Null means "I am not changing this", never "set it to nothing". A decision form that
        // silently blanked an agreed estimate because a field was left empty would be worse than
        // one that refused to submit.
        $this->assertSame('12000.00', (string) $change->cost_impact);
        $this->assertSame(5, (int) $change->schedule_impact_days);
        $this->assertSame('deferred', $change->disposition?->value);
    }

    #[Test]
    public function a_saving_is_recorded_as_a_negative_cost_and_not_refused(): void
    {
        $change = $this->raisedBy($this->chidi);

        // Omitting an item is a change too, and it is worth less than nothing. A register that only
        // accepted positive figures would quietly force every saving to be recorded somewhere else.
        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), [
                'disposition' => 'approved',
                'decision_notes' => 'Handrail specification relaxed.',
                'cost_impact' => '-3200.50',
            ])->assertSessionHasNoErrors();

        $this->assertSame('-3200.50', (string) $change->refresh()->cost_impact);
    }

    // ================================================================== afterwards

    #[Test]
    public function a_decided_change_is_closed_out_by_somebody_other_than_the_decider(): void
    {
        $change = $this->decided($this->raisedBy($this->chidi), 'approved');

        // Closing sits behind `review`, not `approve` and not `edit`: filing a decided request away
        // is administration, and it should be neither the decider's chore nor available to somebody
        // who may only reword the description.
        $this->actingFresh($this->chidi)
            ->post(route('change-requests.close', $change))
            ->assertForbidden();

        $this->actingFresh($this->grace)
            ->post(route('change-requests.close', $change))
            ->assertSessionHasNoErrors();

        $this->assertSame('closed', $change->refresh()->status?->key);
    }

    #[Test]
    public function an_undecided_change_cannot_be_closed(): void
    {
        $change = $this->raisedBy($this->chidi);

        // Closing an undecided change would leave the register saying the matter is finished while
        // nobody ever answered it. Refused by the policy rather than by the service, so it is a 403
        // and not a validation error — the same shape as every other "not in that state" refusal in
        // the engineering registers.
        $this->actingFresh($this->grace)
            ->post(route('change-requests.close', $change))
            ->assertForbidden();

        $this->assertSame('draft', $change->refresh()->status?->key);
    }

    // ================================================================== fixtures

    /** @param  array<string,mixed>  $attributes */
    private function raisedBy(User $actor, array $attributes = []): ChangeRequest
    {
        return $this->makeChangeRequest($this->org, $actor, 'Additional piling', [
            'project_id' => $this->project->id,
            'description' => 'Ten additional piles to the north abutment.',
            'origin' => 'site',
            ...$attributes,
        ]);
    }

    private function decided(ChangeRequest $change, string $disposition, ?string $notes = null): ChangeRequest
    {
        $this->actingFresh($this->grace)
            ->post(route('change-requests.decide', $change), array_filter([
                'disposition' => $disposition,
                'decision_notes' => $notes ?? ($disposition === 'approved' ? null : 'Because.'),
            ], fn ($v) => $v !== null))
            ->assertSessionHasNoErrors();

        return $change->refresh();
    }
}
