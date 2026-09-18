<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\DisciplinaryCase;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * Conduct cases — the register with the least reach in the platform, and the tests are mostly about
 * who CANNOT see one.
 *
 *   1. AN ALLEGATION IS NOT NEWS. A case has no parent record and no unit column, so nothing about
 *      being somebody's manager, department head or director reaches it. Either you hold
 *      `disciplinary.view_confidential`, or somebody put you on the case, or you get an empty list
 *      (§68, §75).
 *   2. INVESTIGATING AND DECIDING ARE DIFFERENT PERMISSIONS. The person who gathers the facts is not
 *      thereby the person who hands down the sanction — the two live on separate endpoints with
 *      separate checks, which is the difference between a process and a verdict (§6).
 *   3. AN INVESTIGATOR READS ONE CASE. Assignment writes a Gate 4 row for that record alone and
 *      takes it back when the case is handed on. The investigator does not acquire the register.
 *   4. A CLOSED CASE IS IMMUTABLE. It is what the company points at in front of a tribunal, and a
 *      history that can be tidied afterwards is worth nothing there (§59).
 */
final class DisciplinaryTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $operations;

    private OrgUnit $quality;

    private User $hr;            // Adaeze: opens cases, assigns, manages — and decides nothing

    private User $director;      // Emeka: holds `decide`, and does not investigate

    private User $investigator;  // Ngozi: in Quality, brought in precisely because it is not her area

    private User $head;          // Segun: heads Operations and has every other People permission

    private User $subject;       // Musa: the person the allegation is about

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-04-20 09:00:00');

        $this->org = $this->makeOrganization('Meridian Conduct');
        $this->provisionConfiguration($this->org);
        $this->withinOrganization($this->org, fn () => app(OrganizationProvisioner::class)->seedChecklistTemplates($this->org));

        $this->operations = $this->makeUnit($this->org, 'Operations', OrgUnitType::Department);
        $this->quality = $this->makeUnit($this->org, 'Quality', OrgUnitType::Department);

        $hrRole = $this->makeRole($this->org, 'HR Manager', [
            'employee.view' => 'organization', 'user.view' => 'organization', 'org_unit.view' => 'organization',
            'disciplinary.view' => 'organization', 'disciplinary.create' => 'organization',
            'disciplinary.edit' => 'organization', 'disciplinary.delete' => 'organization',
            'disciplinary.manage' => 'organization', 'disciplinary.view_confidential' => 'organization',
        ]);

        // Decides, and cannot investigate. The separation is the point of the module.
        $directorRole = $this->makeRole($this->org, 'Managing Director', [
            'employee.view' => 'organization',
            'disciplinary.view' => 'organization', 'disciplinary.decide' => 'organization',
            'disciplinary.view_confidential' => 'organization',
        ]);

        // Investigates, and decides nothing. No confidentiality grant either — everything she
        // reaches comes from the ACL row written when she is put on a case.
        $investigatorRole = $this->makeRole($this->org, 'Quality Manager', [
            'employee.view' => 'department',
            'disciplinary.view' => 'department', 'disciplinary.investigate' => 'department',
        ]);

        // The §68 fixture: the subject's own department head, with everything except conduct.
        $headRole = $this->makeRole($this->org, 'Head of Operations', [
            'employee.view' => 'department', 'performance.view' => 'department',
            'employee_movement.view' => 'department',
            'disciplinary.view' => 'department',
        ]);

        $staffRole = $this->makeRole($this->org, 'Technician', ['employee.view' => 'own']);

        $this->hr = $this->makePerson($this->org, 'Adaeze Okafor', null, [$hrRole]);
        $this->director = $this->makePerson($this->org, 'Emeka Chukwu', null, [$directorRole]);
        $this->investigator = $this->makePerson($this->org, 'Ngozi Eze', $this->quality, [$investigatorRole]);
        $this->head = $this->makePerson($this->org, 'Segun Fashola', $this->operations, [$headRole]);
        $this->subject = $this->makePerson($this->org, 'Musa Danjuma', $this->operations, [$staffRole], $this->head->employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== who cannot see it

    #[Test]
    public function an_allegation_does_not_reach_the_subjects_manager_or_the_subject(): void
    {
        $case = $this->openCase();

        $this->assertSame(Confidentiality::Restricted, $case->confidentiality);

        // Segun runs the department Musa works in and holds `disciplinary.view` over it. A case has
        // no unit column and no parent, so departmental reach lands on nothing at all.
        $this->actingFresh($this->head)->get(route('disciplinary-cases.show', $case))->assertForbidden();
        $this->actingFresh($this->head)->get(route('disciplinary-cases.index'))->assertOk()->assertDontSee($case->number);

        // And the person it is about does not browse the file while the investigation runs. What
        // they are entitled to reaches them through the process, not through this register.
        $this->actingFresh($this->subject)->get(route('disciplinary-cases.show', $case))->assertForbidden();

        // Ngozi has not been put on it yet.
        $this->actingFresh($this->investigator)->get(route('disciplinary-cases.show', $case))->assertForbidden();
    }

    #[Test]
    public function an_investigator_reads_the_case_they_were_given_and_no_others(): void
    {
        $first = $this->openCase();
        $second = $this->openCase('A second, unrelated allegation about somebody else.');

        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.investigator', $first), ['investigator_id' => $this->investigator->id])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('investigating', $this->reload($first)->statusKey());

        $this->actingFresh($this->investigator)->get(route('disciplinary-cases.show', $first))->assertOk();
        $this->actingFresh($this->investigator)->get(route('disciplinary-cases.show', $second))->assertForbidden();

        $this->actingFresh($this->investigator)
            ->get(route('disciplinary-cases.index'))
            ->assertOk()
            ->assertSee($first->number)
            ->assertDontSee($second->number);

        // Handed on, and the first investigator stops reading it the same minute.
        $other = $this->makePerson($this->org, 'Tayo Alabi', $this->quality, [
            $this->makeRole($this->org, 'Compliance Officer', [
                'employee.view' => 'department',
                'disciplinary.view' => 'department', 'disciplinary.investigate' => 'department',
            ]),
        ]);

        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.investigator', $first), ['investigator_id' => $other->id])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->investigator)->get(route('disciplinary-cases.show', $first))->assertForbidden();
        $this->actingFresh($other)->get(route('disciplinary-cases.show', $first))->assertOk();
    }

    #[Test]
    public function nobody_investigates_a_case_about_themselves(): void
    {
        $case = $this->openCase();

        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.investigator', $case), ['investigator_id' => $this->subject->id])
            ->assertSessionHasErrors();

        $this->assertNull($this->reload($case)->investigator_id);
    }

    // ================================================================== gathering is not deciding

    #[Test]
    public function the_investigator_writes_the_findings_and_does_not_hand_down_the_sanction(): void
    {
        $case = $this->assignedCase();

        $this->actingFresh($this->investigator)
            ->post(route('disciplinary-cases.investigation', $case), [
                'investigation_notes' => 'Two witnesses on the walkway; the permit was signed but not displayed.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingFresh($this->investigator)
            ->post(route('disciplinary-cases.hearing', $case), ['hearing_on' => '2026-04-27'])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->investigator)
            ->post(route('disciplinary-cases.hearing-notes', $case), [
                'hearing_notes' => 'He accepted the permit was not displayed and said the holder had been taken.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('hearing', $this->reload($case)->statusKey());

        // And there she stops. Deciding is a permission she does not hold, checked at the endpoint.
        $this->actingFresh($this->investigator)
            ->post(route('disciplinary-cases.decide', $case), ['outcome' => 'written_warning'])
            ->assertForbidden();

        // HR runs the register, opened the case, and does not decide it either.
        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.decide', $case), ['outcome' => 'written_warning'])
            ->assertForbidden();

        $this->actingFresh($this->director)
            ->post(route('disciplinary-cases.decide', $case), [
                'outcome' => 'written_warning',
                'outcome_notes' => 'The permit was in force. Displaying it is the part that failed.',
                'sanction_expires_on' => '2027-04-20',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $case = $this->reload($case);
        $this->assertSame('decided', $case->statusKey());
        $this->assertTrue($case->isDecided());
        $this->assertTrue($case->sanctionIsLive());
        $this->assertSame($this->director->id, $case->decided_by);

        // Equally, the person who decides does not write the investigation notes.
        $this->actingFresh($this->director)
            ->post(route('disciplinary-cases.investigation', $case), ['investigation_notes' => 'Rewriting the findings.'])
            ->assertForbidden();
    }

    #[Test]
    public function an_appeal_can_replace_the_finding_and_the_sanction_stops_being_live(): void
    {
        $case = $this->decidedCase();

        $this->actingFresh($this->director)
            ->post(route('disciplinary-cases.appeal', $case), ['appeal_received_on' => '2026-04-20'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->actingFresh($this->director)
            ->post(route('disciplinary-cases.appeal-decision', $case), [
                'appeal_outcome' => 'upheld',
                'outcome' => 'no_case',
                'appeal_notes' => 'The holder had been removed by somebody else that morning.',
            ])
            ->assertSessionHasNoErrors();

        $case = $this->reload($case);
        $this->assertSame('upheld', $case->appeal_outcome);
        $this->assertSame('no_case', $case->outcome);

        // "No case" is not a sanction that lapses in a year. It is not a sanction at all.
        $this->assertFalse($case->sanctionIsLive());
        $this->assertSame(0, $this->withinOrganization($this->org, fn () => DisciplinaryCase::query()->liveSanctions()->count()));
    }

    #[Test]
    public function a_closed_case_is_the_record_and_nobody_edits_it(): void
    {
        $case = $this->decidedCase();

        $this->actingFresh($this->director)
            ->post(route('disciplinary-cases.close', $case))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $case = $this->reload($case);
        $this->assertSame('closed', $case->statusKey());
        $this->assertNotNull($case->closed_at);

        $this->actingFresh($this->hr)->get(route('disciplinary-cases.edit', $case))->assertForbidden();
        $this->actingFresh($this->hr)->delete(route('disciplinary-cases.destroy', $case))->assertForbidden();
        $this->actingFresh($this->hr)->post(route('disciplinary-cases.withdraw', $case), ['reason' => 'Tidying up.'])->assertForbidden();
        $this->actingFresh($this->director)->post(route('disciplinary-cases.decide', $case), ['outcome' => 'no_case'])->assertForbidden();
        $this->actingFresh($this->investigator)
            ->post(route('disciplinary-cases.investigation', $case), ['investigation_notes' => 'One more thought.'])
            ->assertForbidden();
    }

    #[Test]
    public function withdrawing_keeps_the_record_and_says_so(): void
    {
        $case = $this->openCase();

        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.withdraw', $case), ['reason' => 'The complaint was made about the wrong shift.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $case = $this->reload($case);
        $this->assertSame('withdrawn', $case->statusKey());
        $this->assertNotNull($case->id, 'Withdrawing is not deleting.');
    }

    // ================================================================== the boundaries

    #[Test]
    public function a_case_from_another_company_is_not_found(): void
    {
        $case = $this->openCase();

        $other = $this->makeOrganization('Other Engineering');
        $this->provisionConfiguration($other);
        $stranger = $this->makePerson($other, 'Sani Musa', null, [
            $this->makeRole($other, 'HR', [
                'employee.view' => 'organization',
                'disciplinary.view' => 'organization', 'disciplinary.manage' => 'organization',
                'disciplinary.investigate' => 'organization', 'disciplinary.decide' => 'organization',
                'disciplinary.view_confidential' => 'organization',
            ]),
        ]);

        $this->actingFresh($stranger)->get(route('disciplinary-cases.show', $case))->assertNotFound();
        $this->actingFresh($stranger)->post(route('disciplinary-cases.decide', $case), ['outcome' => 'dismissal'])->assertNotFound();
        $this->actingFresh($stranger)
            ->post(route('disciplinary-cases.investigator', $case), ['investigator_id' => $stranger->id])
            ->assertNotFound();
    }

    #[Test]
    public function an_allegation_cannot_be_dated_in_the_future(): void
    {
        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.store'), [
                'employee_id' => $this->subject->employee->id,
                'category' => 'safety',
                'severity' => 'serious',
                'incident_on' => '2026-05-01',   // it is 20 April
                'reported_on' => '2026-04-20',
                'summary' => 'Something that has not happened yet.',
            ])
            ->assertSessionHasErrors('incident_on');

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => DisciplinaryCase::query()->count()));
    }

    // ================================================================== the screens

    #[Test]
    public function every_conduct_screen_renders(): void
    {
        $case = $this->decidedCase();

        $this->actingFresh($this->hr)->get(route('disciplinary-cases.index'))->assertOk()->assertSee($case->number);
        $this->actingFresh($this->hr)->get(route('disciplinary-cases.index', ['view' => 'live', 'severity' => 'serious']))->assertOk();
        $this->actingFresh($this->hr)->get(route('disciplinary-cases.index', ['view' => 'all', 'q' => 'Musa']))->assertOk();
        $this->actingFresh($this->hr)->get(route('disciplinary-cases.create'))->assertOk();
        $this->actingFresh($this->hr)->get(route('disciplinary-cases.edit', $case))->assertOk();

        // Three readers, three different sets of cards on the same page.
        $this->actingFresh($this->hr)->get(route('disciplinary-cases.show', $case))->assertOk()->assertSee('Musa Danjuma');
        $this->actingFresh($this->director)->get(route('disciplinary-cases.show', $case))->assertOk()->assertSee('Revise the outcome');
        $this->actingFresh($this->investigator)->get(route('disciplinary-cases.show', $case))->assertOk()->assertDontSee('Revise the outcome');
    }

    // ================================================================== fixture helpers

    private function openCase(string $summary = 'Worked at height on the flare deck with the permit not displayed.'): DisciplinaryCase
    {
        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.store'), [
                'employee_id' => $this->subject->employee->id,
                'category' => 'safety',
                'severity' => 'serious',
                'incident_on' => '2026-04-18',
                'reported_on' => '2026-04-20',
                'summary' => $summary,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return $this->withinOrganization($this->org, fn () => DisciplinaryCase::query()->latest('id')->firstOrFail()->load('status'));
    }

    private function assignedCase(): DisciplinaryCase
    {
        $case = $this->openCase();

        $this->actingFresh($this->hr)
            ->post(route('disciplinary-cases.investigator', $case), ['investigator_id' => $this->investigator->id])
            ->assertSessionHasNoErrors();

        return $this->reload($case);
    }

    private function decidedCase(): DisciplinaryCase
    {
        $case = $this->assignedCase();

        $this->actingFresh($this->investigator)
            ->post(route('disciplinary-cases.investigation', $case), ['investigation_notes' => 'The permit was in force and not displayed.'])
            ->assertSessionHasNoErrors();

        $this->actingFresh($this->director)
            ->post(route('disciplinary-cases.decide', $case), [
                'outcome' => 'written_warning',
                'outcome_notes' => 'Displaying the permit is the part that failed.',
                'sanction_expires_on' => '2027-04-20',
            ])
            ->assertSessionHasNoErrors();

        return $this->reload($case);
    }

    private function reload(DisciplinaryCase $case): DisciplinaryCase
    {
        return $this->withinOrganization($this->org, fn () => $case->fresh(['status', 'employee']));
    }
}
