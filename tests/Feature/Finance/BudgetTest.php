<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Budget;
use App\Models\Organization;
use App\Models\ProjectCost;
use App\Models\User;
use App\Services\BudgetService;
use App\Services\CostCenterService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class BudgetTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $org;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->makeOrganization('Budget company');
        $role = $this->makeRole($this->org, 'Budget manager', [
            'budget.view' => 'organization', 'budget.create' => 'organization',
            'budget.edit' => 'organization', 'budget.delete' => 'organization',
            'budget.manage' => 'organization', 'budget.approve' => 'organization',
            'project.view' => 'organization', 'org_unit.view' => 'organization',
            'employee.view' => 'organization',
        ]);
        $this->manager = $this->makePerson($this->org, 'Budget Manager', null, [$role]);
        $this->provisionConfiguration($this->org);
    }

    #[Test]
    public function a_budget_sums_its_lines_and_reports_actual_project_costs(): void
    {
        $project = $this->makeProject($this->org, $this->manager, 'Refinery turnaround');
        $center = $this->withinOrganization($this->org, fn () => app(CostCenterService::class)->create([
            'code' => 'PRJ-01', 'name' => 'Turnaround', 'project_id' => $project->id, 'is_active' => true,
        ], $this->manager));
        $budget = $this->budget([
            'cost_center_id' => $center->id,
            'items' => [
                ['category' => 'procurement', 'description' => 'Materials', 'allocated_amount' => '1000.10'],
                ['category' => 'travel', 'description' => 'Site travel', 'allocated_amount' => '200.20'],
            ],
        ]);

        $this->withinOrganization($this->org, function () use ($project) {
            ProjectCost::create([
                'organization_id' => $this->org->id, 'number' => 'CST-TEST-1',
                'source_type' => 'goods_receipt_item', 'source_id' => 999001,
                'cost_type' => 'procurement', 'description' => 'Accepted material',
                'quantity' => 2, 'uom' => 'each', 'unit_price' => 125,
                'currency' => 'USD', 'exchange_rate' => 1, 'amount' => 250,
                'base_currency' => 'USD', 'base_amount' => 250,
                'project_id' => $project->id, 'confidentiality' => 'internal',
                'incurred_at' => now(), 'created_by' => $this->manager->id,
            ]);
        });

        $this->assertSame('1200.30', $budget->fresh()->total_amount);
        $this->assertSame($project->id, $budget->project_id);
        $this->actingFresh($this->manager)->get(route('budgets.show', $budget))
            ->assertOk()->assertSee('USD 250.00')->assertSee('USD 950.30')->assertSee('Materials');
    }

    #[Test]
    public function submission_and_approval_freeze_the_signed_allocation(): void
    {
        $budget = $this->budget();

        $this->withinOrganization($this->org, function () use ($budget) {
            $service = app(BudgetService::class);
            $service->submit($budget, $this->manager);
            $this->assertSame('submitted', $budget->statusKey());
            $service->returnForAmendment($budget, $this->manager);
            $this->assertSame('draft', $budget->statusKey());
            $this->assertNull($budget->submitted_at);
            $service->submit($budget, $this->manager);
            $service->approve($budget, $this->manager);
            $this->assertSame('approved', $budget->statusKey());
            $this->assertNotNull($budget->submitted_at);
            $this->assertNotNull($budget->approved_at);

            try {
                $service->update($budget, $this->attributes(), $this->manager);
                $this->fail('An approved budget must be frozen.');
            } catch (\DomainException $exception) {
                $this->assertStringContainsString('draft budget', $exception->getMessage());
            }
        });

        $rejected = $this->budget(['title' => 'Budget to reject']);
        $this->withinOrganization($this->org, function () use ($rejected) {
            $service = app(BudgetService::class);
            $service->submit($rejected, $this->manager);
            $service->reject($rejected, $this->manager);
            $this->assertSame('rejected', $rejected->statusKey());
        });
    }

    #[Test]
    public function duplicate_categories_are_rejected_before_they_can_double_count_actuals(): void
    {
        $payload = $this->attributes([
            'items' => [
                ['category' => 'procurement', 'description' => 'Plant', 'allocated_amount' => 100],
                ['category' => 'Procurement', 'description' => 'Materials', 'allocated_amount' => 200],
            ],
        ]);

        $this->actingFresh($this->manager)->post(route('budgets.store'), $payload)
            ->assertSessionHasErrors('items');
        $this->assertDatabaseCount('budgets', 0);
    }

    #[Test]
    public function budget_permissions_and_tenant_binding_protect_budgets_and_cost_centers(): void
    {
        $center = $this->withinOrganization($this->org, fn () => app(CostCenterService::class)->create([
            'code' => 'OPS', 'name' => 'Operations', 'is_active' => true,
        ], $this->manager));
        $budget = $this->budget(['cost_center_id' => $center->id]);
        $outsider = $this->makePerson($this->org, 'No Finance Access');

        $this->actingFresh($outsider)->get(route('budgets.show', $budget))->assertForbidden();
        $this->actingFresh($outsider)->get(route('cost-centers.show', $center))->assertForbidden();

        $other = $this->makeOrganization('Other budget company');
        $otherUser = $this->makePerson($other, 'Other Budget Reader', null, [
            $this->makeRole($other, 'Reader', ['budget.view' => 'organization']),
        ]);
        $this->actingFresh($otherUser)->get(route('budgets.show', $budget))->assertNotFound();
        $this->actingFresh($otherUser)->get(route('cost-centers.show', $center))->assertNotFound();

        $this->actingFresh($this->manager)->delete(route('cost-centers.destroy', $center))
            ->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('cost_centers', ['id' => $center->id, 'deleted_at' => null]);
    }

    private function budget(array $overrides = []): Budget
    {
        return $this->withinOrganization($this->org, fn () => app(BudgetService::class)
            ->create($this->attributes($overrides), $this->manager));
    }

    private function attributes(array $overrides = []): array
    {
        return [
            'title' => 'Annual operating budget', 'fiscal_year' => now()->year,
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'owner_id' => $this->manager->id, 'confidentiality' => 'internal',
            'items' => [['category' => 'procurement', 'description' => 'Materials', 'allocated_amount' => '500.00']],
            ...$overrides,
        ];
    }
}
