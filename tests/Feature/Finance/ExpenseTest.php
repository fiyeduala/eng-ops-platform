<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\Organization;
use App\Models\ProjectCost;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CostCenterService;
use App\Services\ExpenseService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsFinance;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class ExpenseTest extends TestCase
{
    use BuildsFinance, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $manager;

    private User $claimant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Meridian Finance');
        $this->provisionConfiguration($this->org);
        $this->manager = $this->makePerson($this->org, 'Ngozi Finance', null, [
            $this->makeRole($this->org, 'Finance manager', $this->financeGrants()),
        ]);
        $this->claimant = $this->makePerson($this->org, 'Emeka Site', null, [
            $this->makeRole($this->org, 'Employee', $this->claimantGrants()),
        ]);
    }

    #[Test]
    public function tax_and_totals_are_worked_out_on_the_server_and_approval_posts_the_cost(): void
    {
        $project = $this->makeProject($this->org, $this->manager, 'Jetty repair');
        $vat = $this->makeTaxCode($this->org, $this->manager, 'VAT', '7.5');

        $this->actingFresh($this->claimant)->post(route('expenses.store'), [
            'title' => 'Hotel for site survey',
            'category' => 'accommodation',
            'expense_date' => now()->toDateString(),
            'amount' => '1000',
            'tax_code_id' => $vat->id,
            'confidentiality' => 'internal',
            // §84: none of these may be taken from the form.
            'tax_amount' => '1',
            'total_amount' => '1',
            'status_id' => 999,
            'currency' => 'EUR',
        ])->assertRedirect();

        $expense = $this->withinOrganization($this->org, fn () => Expense::query()->firstOrFail());
        $this->assertSame('75.00', $expense->tax_amount);
        $this->assertSame('1075.00', $expense->total_amount);
        $this->assertSame('USD', $expense->currency);
        $this->assertSame('draft', $expense->statusKey());
        $this->assertSame($this->claimant->id, $expense->claimant_id);

        // Attach the project as finance would, then let the claimant submit.
        $this->withinOrganization($this->org, fn () => app(ExpenseService::class)
            ->update($expense, ['project_id' => $project->id], $this->manager));

        $this->actingFresh($this->claimant)->post(route('expenses.submit', $expense))->assertRedirect();
        $this->actingFresh($this->manager)->post(route('expenses.approve', $expense), ['comment' => 'Receipts seen.'])
            ->assertSessionHasNoErrors();

        $expense = $this->withinOrganization($this->org, fn () => $expense->fresh());
        $this->assertSame('approved', $expense->statusKey());

        $cost = $this->withinOrganization($this->org, fn () => ProjectCost::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail());
        // Unrecoverable tax is part of what the project spent.
        $this->assertSame('1075.00', (string) $cost->amount);
        $this->assertSame($project->id, $cost->project_id);
        $this->assertSame('accommodation', $cost->cost_type);
    }

    #[Test]
    public function recoverable_tax_is_not_a_project_cost_and_the_rate_is_snapshotted(): void
    {
        $vat = $this->makeTaxCode($this->org, $this->manager, 'VATR', '10', ['is_recoverable' => true]);
        $expense = $this->makeApprovedExpense($this->org, $this->claimant, $this->manager, ['tax_code_id' => $vat->id, 'amount' => '200']);

        $cost = $this->withinOrganization($this->org, fn () => ProjectCost::query()
            ->where('source_type', 'expense')->where('source_id', $expense->id)->firstOrFail());
        $this->assertSame('200.00', (string) $cost->amount);

        // Changing the code later does not rewrite what was claimed.
        $this->withinOrganization($this->org, fn () => $vat->forceFill(['rate' => '25'])->save());
        $fresh = $this->withinOrganization($this->org, fn () => $expense->fresh());
        $this->assertSame(10.0, (float) $fresh->tax_rate);
        $this->assertTrue((bool) $fresh->tax_recoverable);
        $this->assertSame('220.00', $fresh->total_amount);
    }

    #[Test]
    public function the_claimant_cannot_decide_their_own_claim_unless_the_company_waives_it_and_the_waiver_is_recorded(): void
    {
        $expense = $this->makeExpense($this->org, $this->manager, ['title' => 'My own taxi']);
        $this->withinOrganization($this->org, fn () => app(ExpenseService::class)->submit($expense, $this->manager));

        $this->actingFresh($this->manager)->post(route('expenses.approve', $expense))
            ->assertSessionHasErrors('status_id');
        $this->assertSame('submitted', $this->withinOrganization($this->org, fn () => $expense->fresh()->statusKey()));

        $this->financeSetting($this->org, 'finance.separate_claimant_and_approver', false);

        $this->actingFresh($this->manager)->post(route('expenses.approve', $expense))->assertSessionHasNoErrors();
        $this->assertSame('approved', $this->withinOrganization($this->org, fn () => $expense->fresh()->statusKey()));

        $entry = $this->withinOrganization($this->org, fn () => ActivityLog::query()
            ->where('subject_type', 'expense')->where('subject_id', $expense->id)
            ->where('verb', 'approved')->firstOrFail());
        $this->assertTrue((bool) ($entry->properties['self_approved'] ?? false));
    }

    #[Test]
    public function a_returned_claim_comes_back_to_the_claimant_as_an_editable_draft_and_a_reason_is_required(): void
    {
        $expense = $this->makeExpense($this->org, $this->claimant);
        $this->withinOrganization($this->org, fn () => app(ExpenseService::class)->submit($expense, $this->claimant));

        $this->actingFresh($this->manager)->post(route('expenses.return', $expense), ['reason' => ''])
            ->assertSessionHasErrors();
        $this->actingFresh($this->manager)->post(route('expenses.reject', $expense), ['reason' => ''])
            ->assertSessionHasErrors();
        $this->assertSame('submitted', $this->withinOrganization($this->org, fn () => $expense->fresh()->statusKey()));

        $this->actingFresh($this->manager)->post(route('expenses.return', $expense), ['reason' => 'Attach the receipt.'])
            ->assertSessionHasNoErrors();
        $expense = $this->withinOrganization($this->org, fn () => $expense->fresh());
        $this->assertSame('draft', $expense->statusKey());
        $this->assertSame('Attach the receipt.', $expense->decision_reason);

        $this->actingFresh($this->claimant)->get(route('expenses.edit', $expense))->assertOk();
        $this->actingFresh($this->claimant)->put(route('expenses.update', $expense), [
            'title' => 'Site visit travel',
            'category' => 'travel',
            'expense_date' => now()->toDateString(),
            'amount' => '900',
            'receipt_reference' => 'RCPT-118',
            'confidentiality' => 'internal',
        ])->assertSessionHasNoErrors();
        $this->assertSame('900.00', $this->withinOrganization($this->org, fn () => $expense->fresh()->total_amount));

        // Nothing but a draft can be edited.
        $this->withinOrganization($this->org, fn () => app(ExpenseService::class)->submit($expense->fresh(), $this->claimant));
        $this->actingFresh($this->claimant)->get(route('expenses.edit', $expense))->assertForbidden();
    }

    #[Test]
    public function a_claim_typed_in_by_finance_belongs_to_the_claimant_and_nobody_else_at_own_scope(): void
    {
        $colleague = $this->makePerson($this->org, 'Bisi Stores', null, [
            $this->makeRole($this->org, 'Employee two', $this->claimantGrants()),
        ]);

        $this->actingFresh($this->manager)->post(route('expenses.store'), [
            'title' => 'Fuel advance',
            'category' => 'fuel',
            'expense_date' => now()->toDateString(),
            'amount' => '50',
            'claimant_id' => $this->claimant->id,
            'confidentiality' => 'internal',
        ])->assertRedirect();
        $expense = $this->withinOrganization($this->org, fn () => Expense::query()->firstOrFail());

        $this->assertSame($this->claimant->id, $expense->claimant_id);
        $this->actingFresh($this->claimant)->get(route('expenses.show', $expense))->assertOk();
        $this->actingFresh($colleague)->get(route('expenses.show', $expense))->assertForbidden();
        $this->actingFresh($colleague)->get(route('expenses.index'))->assertOk()->assertDontSee('Fuel advance');
    }

    #[Test]
    public function an_employee_cannot_raise_a_claim_in_somebody_elses_name(): void
    {
        $this->actingFresh($this->claimant)->post(route('expenses.store'), [
            'title' => 'Claim for my friend',
            'category' => 'meals',
            'expense_date' => now()->toDateString(),
            'amount' => '40',
            'claimant_id' => $this->manager->id,
            'confidentiality' => 'internal',
        ])->assertForbidden();

        $this->assertSame(0, $this->withinOrganization($this->org, fn () => Expense::query()->count()));
    }

    #[Test]
    public function approved_expenses_count_towards_budget_actuals(): void
    {
        $project = $this->makeProject($this->org, $this->manager, 'Pipeline tie-in');
        $center = $this->withinOrganization($this->org, fn () => app(CostCenterService::class)->create([
            'code' => 'PIPE', 'name' => 'Pipeline', 'project_id' => $project->id, 'is_active' => true,
        ], $this->manager));
        $budget = $this->withinOrganization($this->org, fn () => app(BudgetService::class)->create([
            'title' => 'Tie-in budget',
            'cost_center_id' => $center->id,
            'fiscal_year' => (int) now()->format('Y'),
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'confidentiality' => 'internal',
            'items' => [['category' => 'travel', 'description' => 'Travel', 'allocated_amount' => '5000']],
        ], $this->manager));

        $this->makeApprovedExpense($this->org, $this->claimant, $this->manager, ['project_id' => $project->id, 'amount' => '1234.50']);

        $actual = $this->withinOrganization($this->org, fn () => (string) $budget->fresh()->actualCosts()->sum('amount'));
        $this->assertSame(1234.5, (float) $actual);
    }
}
