<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Engines\Organization\OrganizationProvisioner;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Organization;
use App\Models\OrgUnit;
use App\Models\PayComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Services\PayrollService;
use App\Support\Enums\OrgUnitType;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

/**
 * PHASE 6'S EXIT CRITERION, for the register that most wanted an exception: a payroll run is
 * approved at approvals.decide, the same URL that decides a drawing, a leave request and a
 * promotion. There is no payroll-runs.approve route and the first test says so out loud.
 *
 * The five other things a payroll module has to get right, and what each test here defends:
 *
 *   1. ONE CODE PATH, TWO COUNTRIES (§47). A Nigerian company running PAYE and pension and a British
 *      one running PAYE and National Insurance are calculated by the same class, and that class
 *      names neither country. If anybody ever writes `if ($country === ...)` into the calculator,
 *      `two_companies_in_two_countries_are_paid_by_one_code_path` is what fails.
 *   2. NOBODY READS SOMEBODY ELSE'S PAY. Not a line manager over their own team, not a colleague
 *      guessing an id in the address bar (§84, §94). And the person themselves always can — which is
 *      a requirement rather than a courtesy, and which took a `user_id` column on the payslip to
 *      make true, because `own` cannot reach through a relation to the employee.
 *   3. APPROVAL LOCKS IT AND NOTHING UNLOCKS IT (§59). A payslip is evidence of money that moved. A
 *      run editable afterwards is worth nothing to the person querying their pay or to the auditor
 *      asking what was actually paid.
 *   4. NO FIGURE IS EVER ACCEPTED FROM A FORM. Every total on a run is produced by calculating it,
 *      and posting one changes nothing (§84).
 *   5. SOMEBODY WITH NO SALARY IS NOT SILENTLY DROPPED. They get a payslip that pays nothing and
 *      says why, because the alternative is that the first person to notice is them, on pay day.
 */
final class PayrollTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private OrgUnit $engineering;

    private User $hr;           // Amaka: opens and calculates runs, reads everything

    private User $director;     // Tobi: Amaka's line manager, decides the run

    private User $manager;      // Ifeanyi: head of Engineering — sees his team, not their pay

    private User $employee;     // Chuka: is paid, and reads his own payslip and no other

    private User $colleague;    // Ngozi: also on the payroll, and the IDOR target

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 08:00:00');

        $this->org = $this->makeOrganization('Meridian Payroll');
        $this->provisionConfiguration($this->org);
        $this->withinOrganization($this->org, fn () => app(OrganizationProvisioner::class)->seedPayrollWorkflow($this->org));

        $this->engineering = $this->makeUnit($this->org, 'Engineering', OrgUnitType::Department);

        $hrRole = $this->makeRole($this->org, 'HR Manager', [
            'employee.view' => 'organization', 'org_unit.view' => 'organization', 'user.view' => 'organization',
            'payroll.view' => 'organization', 'payroll.create' => 'organization',
            'payroll.edit' => 'organization', 'payroll.delete' => 'organization',
            'payroll.run' => 'organization', 'payroll.export' => 'organization',
            'payroll.view_confidential' => 'organization',
        ]);

        // The director decides runs and does not produce them. Note what is absent: `payroll.run`,
        // so he cannot open the register he approves against — which is the separation the module
        // exists to hold, and the reason he is sent to his approval inbox instead.
        $directorRole = $this->makeRole($this->org, 'Managing Director', [
            'employee.view' => 'organization',
            'payroll.view' => 'organization', 'payroll.approve' => 'organization',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        // THE FIXTURE THAT MATTERS MOST. A department head with full reach over his own people and
        // every permission a line manager plausibly holds — and no payroll grant at all, because
        // managing somebody has never implied being allowed to see what they earn.
        $managerRole = $this->makeRole($this->org, 'Head of Engineering', [
            'employee.view' => 'department',
            'leave.view' => 'department', 'timesheet.view' => 'department',
            'approval.view' => 'assigned', 'approval.act' => 'assigned',
        ]);

        // The self-service grant, exactly as the general-employee template carries it.
        $staffRole = $this->makeRole($this->org, 'Engineer', [
            'employee.view' => 'own',
            'payroll.view' => 'own',
        ]);

        $this->director = $this->makePerson($this->org, 'Tobi Ajayi', $this->engineering, [$directorRole]);
        $this->hr = $this->makePerson($this->org, 'Amaka Nwosu', $this->engineering, [$hrRole], $this->director->employee);
        $this->manager = $this->makePerson($this->org, 'Ifeanyi Eze', $this->engineering, [$managerRole], $this->director->employee);
        $this->employee = $this->makePerson($this->org, 'Chuka Madu', $this->engineering, [$staffRole], $this->manager->employee);
        $this->colleague = $this->makePerson($this->org, 'Ngozi Bello', $this->engineering, [$staffRole], $this->manager->employee);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ================================================================== the exit criterion

    #[Test]
    public function a_payroll_run_is_decided_in_the_same_inbox_documents_use(): void
    {
        $this->assertFalse(Route::has('payroll-runs.approve'), 'A payroll run must not have its own approve route.');

        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        $run = $this->calculatedRun();

        $this->actingFresh($this->hr)
            ->post(route('payroll-runs.submit', $run))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $stage = $this->pendingStage($run);

        // The clerk who produced the figures cannot wave them through, and neither can somebody
        // being paid by the run. Only the assignee decides — and at the generic endpoint.
        $this->actingFresh($this->hr)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();
        $this->actingFresh($this->employee)->post(route('approvals.decide', $stage), ['decision' => 'approve'])->assertForbidden();

        $this->actingFresh($this->director)
            ->post(route('approvals.decide', $stage), ['decision' => 'approve', 'comment' => 'Agreed.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $run = $this->reload($run);

        $this->assertSame('approved', $run->statusKey());
        $this->assertNotNull($run->locked_at, 'Approval must lock the run — and only approval may.');
    }

    // ================================================================== §47: one code path

    #[Test]
    public function two_companies_in_two_countries_are_paid_by_one_code_path(): void
    {
        // Meridian: Nigerian. Basic, 8% pension deducted before tax, then progressive PAYE.
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '500000');
        $nigerian = $this->calculatedRun();
        $meridianSlip = $this->slipFor($this->org, $nigerian, $this->employee);

        // 500,000 basic. Pension 8% = 40,000, which reduces taxable to 460,000. PAYE: 7% of the
        // first 300,000 = 21,000, plus 20% of the remaining 160,000 = 32,000, so 53,000. Deductions
        // 93,000 and net 407,000.
        $this->assertSame('500000.00', $meridianSlip->gross_amount);
        $this->assertSame('93000.00', $meridianSlip->deduction_amount);
        $this->assertSame('407000.00', $meridianSlip->net_amount);

        // Kestrel: British. A different company, a different currency, and a structure whose rows say
        // "National Insurance" rather than "Pension Contribution". Nothing in the platform is told
        // which country either of them is in, because there is nowhere to put it.
        $kestrel = $this->makeOrganization('Kestrel Engineering');
        $this->provisionConfiguration($kestrel);
        $this->withinOrganization($kestrel, fn () => app(OrganizationProvisioner::class)->seedPayrollWorkflow($kestrel));

        $kestrelUnit = $this->makeUnit($kestrel, 'Projects', OrgUnitType::Department);
        $kestrelHr = $this->makePerson($kestrel, 'Alice Fenwick', $kestrelUnit, [
            $this->makeRole($kestrel, 'Payroll Officer', [
                'employee.view' => 'organization',
                'payroll.view' => 'organization', 'payroll.create' => 'organization',
                'payroll.edit' => 'organization', 'payroll.run' => 'organization',
                'payroll.view_confidential' => 'organization',
            ]),
        ]);
        $kestrelStaff = $this->makePerson($kestrel, 'Gareth Pryce', $kestrelUnit, []);

        $kestrelRun = $this->withinOrganization($kestrel, function () use ($kestrel, $kestrelHr, $kestrelStaff) {
            $this->makeComponent($kestrel, 'BASIC', 'Basic Salary', 'earning', 'percent', 'basic', '100', 10, [
                'is_taxable' => true, 'is_pensionable' => true,
            ]);
            $this->makeComponent($kestrel, 'PAYE', 'PAYE Income Tax', 'deduction', 'banded', 'taxable_gross', null, 50, [], [
                ['from_amount' => '0', 'to_amount' => '1047', 'rate' => '0'],
                ['from_amount' => '1047', 'to_amount' => null, 'rate' => '20'],
            ]);
            $this->makeComponent($kestrel, 'NI', 'National Insurance', 'deduction', 'percent', 'gross', '8', 60);

            $this->makeSalary($kestrel, $kestrelStaff->employee, '3000', 'GBP');

            return $this->makeRun($kestrelHr, 'GBP');
        });

        $kestrelSlip = $this->slipFor($kestrel, $kestrelRun, $kestrelStaff);

        // 3,000 gross. PAYE 20% of (3,000 - 1,047) = 390.60, NI 8% of 3,000 = 240, net 2,369.40.
        // The figures are checked and not merely the fact that something came out, because §47 is
        // only discharged if the generic engine is also right: one code path that pays both
        // companies the wrong amount is not an achievement.
        $this->assertSame('GBP', $kestrelSlip->currency);
        $this->assertSame('630.60', $kestrelSlip->deduction_amount);
        $this->assertSame('2369.40', $kestrelSlip->net_amount);

        // Two companies, two tax systems, two currencies, one calculator — and the proof that
        // nothing branched on a country is that there is nowhere in the schema to record one.
        $this->assertSame('NGN', $meridianSlip->currency);
        $this->assertFalse(
            Schema::hasColumn('pay_components', 'country'),
            'A country column on the pay structure would be the platform taking on tax law.',
        );
    }

    // ================================================================== confidentiality

    #[Test]
    public function an_employee_reads_their_own_payslip_and_only_their_own(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        $this->setSalary($this->colleague->employee, '650000');
        $run = $this->approvedRun();

        $mine = $this->slipFor($this->org, $run, $this->employee);
        $theirs = $this->slipFor($this->org, $run, $this->colleague);

        // THE POSITIVE HALF, AND IT IS THE HALF THAT SILENTLY REGRESSED ONCE. `own` resolves against
        // the payslip's own `user_id`; before that column existed this page returned an empty list
        // to every employee in the company and looked from the outside exactly like a working
        // feature. A test that only checked the forbidding would have passed throughout.
        $this->actingFresh($this->employee)->get(route('payslips.mine'))
            ->assertOk()
            ->assertSee($mine->number)
            ->assertDontSee($theirs->number);

        $this->actingFresh($this->employee)->get(route('payslips.show', $mine))->assertOk();
        $this->actingFresh($this->employee)->get(route('payslips.download', $mine))->assertOk();

        // §94: the same route, a colleague's id, nothing but the number changed.
        $this->actingFresh($this->employee)->get(route('payslips.show', $theirs))->assertForbidden();
        $this->actingFresh($this->employee)->get(route('payslips.download', $theirs))->assertForbidden();
    }

    #[Test]
    public function a_line_manager_reaches_their_team_but_not_their_teams_pay(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        $run = $this->approvedRun();
        $slip = $this->slipFor($this->org, $run, $this->employee);

        // He reaches the person. This is his own report and he manages them.
        $this->actingFresh($this->manager)->get(route('employees.show', $this->employee->employee))->assertOk();

        // And not one thing about their pay, by any door (§68).
        $this->actingFresh($this->manager)->get(route('payslips.show', $slip))->assertForbidden();
        $this->actingFresh($this->manager)->get(route('payslips.download', $slip))->assertForbidden();
        $this->actingFresh($this->manager)->get(route('employee-pay.show', $this->employee->employee))->assertForbidden();
        $this->actingFresh($this->manager)->get(route('payroll-runs.index'))->assertForbidden();
    }

    #[Test]
    public function the_pay_file_takes_more_than_reaching_the_person(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');

        // HR holds `payroll.view_confidential` and reads it.
        $this->actingFresh($this->hr)->get(route('employee-pay.show', $this->employee->employee))->assertOk();

        // The employee themselves does not, on their own record. Self-service reaches the payslip —
        // what was actually paid — and not the salary history, which is HR's file about them.
        $this->actingFresh($this->employee)->get(route('employee-pay.show', $this->employee->employee))->assertForbidden();

        // And neither does the director, who approves the money without reading individual salaries.
        $this->actingFresh($this->director)->get(route('employee-pay.show', $this->employee->employee))->assertForbidden();
    }

    // ================================================================== §75: tenancy

    #[Test]
    public function one_company_cannot_reach_another_companys_payroll(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        $run = $this->approvedRun();
        $slip = $this->slipFor($this->org, $run, $this->employee);

        $other = $this->makeOrganization('Kestrel Engineering');
        $this->provisionConfiguration($other);
        $otherUnit = $this->makeUnit($other, 'Projects', OrgUnitType::Department);
        $otherHr = $this->makePerson($other, 'Alice Fenwick', $otherUnit, [
            $this->makeRole($other, 'Payroll Officer', [
                'employee.view' => 'organization',
                'payroll.view' => 'organization', 'payroll.run' => 'organization',
                'payroll.view_confidential' => 'organization',
            ]),
        ]);

        // Every payroll permission there is, held at organization scope — in the wrong company. The
        // ids are real and sequential and guessable, and it changes nothing (§75).
        $this->actingFresh($otherHr)->get(route('payroll-runs.show', $run))->assertNotFound();
        $this->actingFresh($otherHr)->get(route('payslips.show', $slip))->assertNotFound();
        $this->actingFresh($otherHr)->get(route('employee-pay.show', $this->employee->employee))->assertNotFound();
    }

    // ================================================================== §59: locking

    #[Test]
    public function an_approved_run_cannot_be_edited_recalculated_or_deleted(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        $run = $this->approvedRun();

        // HR holds every payroll permission in the catalogue. It is the lock that refuses, not the
        // permissions — which is the point: there is nobody to escalate to (§59).
        $this->actingFresh($this->hr)->put(route('payroll-runs.update', $run), [
            'name' => 'Quietly different', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'frequency' => 'monthly', 'currency' => 'NGN',
        ])->assertForbidden();

        $this->actingFresh($this->hr)->post(route('payroll-runs.calculate', $run))->assertForbidden();
        $this->actingFresh($this->hr)->delete(route('payroll-runs.destroy', $run))->assertForbidden();

        // Cancelling is the interesting one: it is a perfectly good verb on a run that has not been
        // approved, and it would take the whole thing off the register. A well-formed request with
        // every field the form wants is still refused.
        $this->actingFresh($this->hr)
            ->post(route('payroll-runs.cancel', $run), ['reason' => 'Raised in error.'])
            ->assertForbidden();

        $this->assertSame('September 2026 payroll', $this->reload($run)->name);
    }

    #[Test]
    public function a_mistake_in_an_approved_run_is_fixed_by_a_correcting_run(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        $run = $this->approvedRun();

        // §84: the period and the currency are NOT fields on this form, and the two that are posted
        // here are the only two the controller reads. The rest is not validated away — it is never
        // looked at, and the correction takes its period from the run it corrects.
        $this->actingFresh($this->hr)
            ->post(route('payroll-runs.correct', $run), [
                'name' => 'September 2026 correction',
                'notes' => 'Overtime for the Kaduna crew was missed.',
                'period_start' => '2020-01-01', 'currency' => 'USD', 'frequency' => 'weekly',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $correction = $this->withinOrganization(
            $this->org,
            fn () => PayrollRun::query()->where('corrects_payroll_run_id', $run->getKey())->firstOrFail(),
        );

        $this->assertSame('2026-09-01', $correction->period_start->toDateString());
        $this->assertSame('2026-09-30', $correction->period_end->toDateString());
        $this->assertSame('NGN', $correction->currency);
        $this->assertSame('monthly', $correction->frequency);

        // And the original is untouched, on the register, still locked. Both runs exist and the
        // history says what happened, which is how a correction works in the real world.
        $this->assertNotNull($this->reload($run)->locked_at);
    }

    // ================================================================== §84: no posted figures

    #[Test]
    public function a_total_cannot_be_posted_onto_a_run(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        $run = $this->calculatedRun();
        $realNet = $run->net_total;

        $this->actingFresh($this->hr)->put(route('payroll-runs.update', $run), [
            'name' => 'September 2026 payroll',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
            'frequency' => 'monthly', 'currency' => 'NGN',
            // None of these are fields on the form. All of them are what somebody would try.
            'net_total' => '99999999.00', 'gross_total' => '99999999.00',
            'deduction_total' => '0.00', 'payslip_count' => 999,
            'locked_at' => '2026-09-20 00:00:00',
            'organization_id' => 999999,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $run = $this->reload($run);

        $this->assertSame($realNet, $run->net_total, 'A total may only come from calculating the run.');
        $this->assertNull($run->locked_at, 'A run cannot be locked by posting to it — only by being approved.');
        $this->assertSame($this->org->getKey(), $run->organization_id);
    }

    // ================================================================== skipped payslips

    #[Test]
    public function somebody_with_no_salary_gets_a_payslip_saying_so_rather_than_disappearing(): void
    {
        $this->seedStructure();
        $this->setSalary($this->employee->employee, '400000');
        // Ngozi is deliberately left with no salary row — the commonest real failure in payroll, a
        // new starter whose paperwork did not arrive.

        $run = $this->calculatedRun();
        $skipped = $this->slipFor($this->org, $run, $this->colleague);

        $this->assertTrue($skipped->wasSkipped(), 'Somebody with no salary must not vanish from the run.');
        $this->assertSame('0.00', $skipped->net_amount);
        $this->assertStringContainsString('No salary is on record', (string) $skipped->skipped_reason);

        // And it is said where somebody will act on it — on the run, before pay day, rather than in
        // a log nobody reads.
        $this->actingFresh($this->hr)->get(route('payroll-runs.show', $run))
            ->assertOk()
            ->assertSee('No salary is on record');
    }

    // ================================================================== helpers

    /** Meridian's structure: basic, 8% pension deducted before tax, then progressive PAYE. */
    private function seedStructure(): void
    {
        $this->withinOrganization($this->org, function () {
            $this->makeComponent($this->org, 'BASIC', 'Basic Salary', 'earning', 'percent', 'basic', '100', 10, [
                'is_taxable' => true, 'is_pensionable' => true,
            ]);
            $this->makeComponent($this->org, 'PENSION', 'Pension Contribution', 'deduction', 'percent', 'pensionable', '8', 40, [
                'reduces_taxable' => true,
            ]);
            $this->makeComponent($this->org, 'PAYE', 'PAYE', 'deduction', 'banded', 'taxable_gross', null, 50, [], [
                ['from_amount' => '0', 'to_amount' => '300000', 'rate' => '7'],
                ['from_amount' => '300000', 'to_amount' => null, 'rate' => '20'],
            ]);
        });
    }

    /**
     * @param  array<string,mixed>  $extra
     * @param  array<int,array<string,mixed>>  $bands
     */
    private function makeComponent(
        Organization $organization,
        string $code,
        string $name,
        string $kind,
        string $method,
        string $basis,
        ?string $rate,
        int $sequence,
        array $extra = [],
        array $bands = [],
    ): PayComponent {
        $component = PayComponent::create([
            'organization_id' => $organization->getKey(),
            'code' => $code,
            'name' => $name,
            'kind' => $kind,
            'method' => $method,
            'basis' => $basis,
            'rate' => $rate ?? '0',
            'sequence' => $sequence,
            'applies_to_all' => true,
            'is_active' => true,
            'is_prorated' => true,
            ...$extra,
        ]);

        foreach ($bands as $index => $band) {
            $component->bands()->create([
                'organization_id' => $organization->getKey(),
                'from_amount' => $band['from_amount'],
                'to_amount' => $band['to_amount'],
                'rate' => $band['rate'],
                'sequence' => ($index + 1) * 10,
            ]);
        }

        return $component;
    }

    private function setSalary(Employee $employee, string $amount): EmployeeSalary
    {
        return $this->withinOrganization($this->org, fn () => $this->makeSalary($this->org, $employee, $amount, 'NGN'));
    }

    private function makeSalary(Organization $organization, Employee $employee, string $amount, string $currency): EmployeeSalary
    {
        return EmployeeSalary::create([
            'organization_id' => $organization->getKey(),
            'employee_id' => $employee->getKey(),
            'basic_amount' => $amount,
            'currency' => $currency,
            'frequency' => 'monthly',
            'effective_from' => '2026-01-01',
        ]);
    }

    private function calculatedRun(): PayrollRun
    {
        return $this->withinOrganization($this->org, fn () => $this->makeRun($this->hr, 'NGN'));
    }

    /** A run taken all the way through the workflow, so the locking tests start from reality. */
    private function approvedRun(): PayrollRun
    {
        $run = $this->calculatedRun();

        $this->actingFresh($this->hr)->post(route('payroll-runs.submit', $run))->assertSessionHasNoErrors();

        $this->actingFresh($this->director)
            ->post(route('approvals.decide', $this->pendingStage($run)), ['decision' => 'approve'])
            ->assertSessionHasNoErrors();

        return $this->reload($run);
    }

    /** Called inside an organization context, because the service resolves the tenant from it. */
    private function makeRun(User $actor, string $currency): PayrollRun
    {
        $payroll = app(PayrollService::class);

        $run = $payroll->create([
            'name' => 'September 2026 payroll',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'pay_date' => '2026-09-30',
            'frequency' => 'monthly',
            'currency' => $currency,
        ], $actor);

        $payroll->calculate($run, $actor);

        return $run->refresh();
    }

    private function slipFor(Organization $organization, PayrollRun $run, User $person): Payslip
    {
        return $this->withinOrganization($organization, fn () => Payslip::query()
            ->where('payroll_run_id', $run->getKey())
            ->where('employee_id', $person->employee->getKey())
            ->firstOrFail());
    }

    private function pendingStage(Model $subject): WorkflowStageInstance
    {
        return $this->withinOrganization($this->org, fn () => WorkflowStageInstance::query()
            ->pending()
            ->whereHas('instance', fn ($q) => $q->where('subject_type', 'payroll_run')->where('subject_id', $subject->getKey()))
            ->firstOrFail());
    }

    private function reload(PayrollRun $run): PayrollRun
    {
        return $this->withinOrganization($this->org, fn () => $run->fresh(['status']));
    }
}
