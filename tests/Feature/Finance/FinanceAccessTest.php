<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\Income;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\IncomeService;
use App\Services\PaymentService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsCommercial;
use Tests\Support\BuildsFinance;
use Tests\Support\BuildsTenants;
use Tests\TestCase;

final class FinanceAccessTest extends TestCase
{
    use BuildsCommercial, BuildsFinance, BuildsTenants, RefreshDatabase;

    protected string $seeder = PermissionSeeder::class;

    protected bool $seed = true;

    private Organization $meridian;

    private Organization $kestrel;

    private User $manager;

    private User $claimant;

    private User $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meridian = $this->makeOrganization('Meridian Engineering');
        $this->kestrel = $this->makeOrganization('Kestrel Energy');
        $this->provisionConfiguration($this->meridian);
        $this->provisionConfiguration($this->kestrel);
        $this->manager = $this->makePerson($this->meridian, 'Ngozi Finance', null, [
            $this->makeRole($this->meridian, 'Finance manager', $this->financeGrants()),
        ]);
        $this->claimant = $this->makePerson($this->meridian, 'Emeka Site', null, [
            $this->makeRole($this->meridian, 'Employee', $this->claimantGrants()),
        ]);
        $this->rival = $this->makePerson($this->kestrel, 'Kestrel Finance', null, [
            $this->makeRole($this->kestrel, 'Finance manager', $this->financeGrants()),
        ]);
    }

    #[Test]
    public function every_finance_screen_renders_for_the_finance_manager(): void
    {
        $vat = $this->makeTaxCode($this->meridian, $this->manager, 'VAT', '7.5');
        $expense = $this->makeApprovedExpense($this->meridian, $this->claimant, $this->manager, ['tax_code_id' => $vat->id]);
        $income = $this->makeIssuedIncome($this->meridian, $this->manager);
        $tin = $this->makeTin($this->meridian, $this->manager);
        $payment = $this->withinOrganization($this->meridian, fn () => app(PaymentService::class)->create([
            'direction' => 'outgoing', 'payable_type' => 'expense', 'payable_id' => $expense->id,
            'amount' => '100', 'method' => 'cash', 'confidentiality' => 'internal',
        ], $this->manager));

        $as = $this->actingFresh($this->manager);

        foreach ([
            route('expenses.index'), route('expenses.create'), route('expenses.show', $expense),
            route('incomes.index'), route('incomes.create'), route('incomes.show', $income),
            route('payments.index'), route('payments.index', ['direction' => 'outgoing']), route('payments.create'),
            route('payments.show', $payment), route('payments.edit', $payment),
            route('petty-cash.index'), route('petty-cash.create'), route('petty-cash.show', $tin), route('petty-cash.edit', $tin),
            route('tax-codes.index'), route('tax-codes.create'), route('tax-codes.edit', $vat),
        ] as $url) {
            $as->get($url)->assertOk();
        }

        $as->get(route('expenses.show', $expense))->assertSee('1,075.00');
    }

    #[Test]
    public function another_companys_finance_records_are_not_found(): void
    {
        $theirClaimant = $this->makePerson($this->kestrel, 'Kestrel Claimant', null, [
            $this->makeRole($this->kestrel, 'Employee', $this->claimantGrants()),
        ]);
        $expense = $this->makeApprovedExpense($this->kestrel, $theirClaimant, $this->rival);
        $income = $this->makeIssuedIncome($this->kestrel, $this->rival);
        $tin = $this->makeTin($this->kestrel, $this->rival);
        $code = $this->makeTaxCode($this->kestrel, $this->rival, 'VAT', '20');
        $payment = $this->withinOrganization($this->kestrel, fn () => app(PaymentService::class)->create([
            'direction' => 'outgoing', 'payable_type' => 'expense', 'payable_id' => $expense->id,
            'amount' => '10', 'method' => 'cash', 'confidentiality' => 'internal',
        ], $this->rival));

        $as = $this->actingFresh($this->manager);

        foreach ([
            ['get', route('expenses.show', $expense)],
            ['get', route('incomes.show', $income)],
            ['get', route('payments.show', $payment)],
            ['get', route('petty-cash.show', $tin)],
            ['get', route('tax-codes.edit', $code)],
            ['post', route('payments.approve', $payment)],
            ['post', route('incomes.cancel', $income)],
            ['post', route('petty-cash.disburse', $tin)],
            ['delete', route('tax-codes.destroy', $code)],
        ] as [$method, $url]) {
            $this->assertSame(404, $as->{$method}($url)->getStatusCode(), $url.' leaked another company\'s record.');
        }
    }

    #[Test]
    public function a_form_cannot_point_a_record_at_another_companys_tax_code_client_or_project(): void
    {
        $foreignCode = $this->makeTaxCode($this->kestrel, $this->rival, 'KVAT', '20');
        $foreignClient = $this->makeClient($this->kestrel, $this->rival, 'Kestrel Holdings');
        $foreignProject = $this->makeProject($this->kestrel, $this->rival, 'Kestrel wind farm');

        $base = [
            'title' => 'Billing', 'category' => 'contract_billing', 'income_date' => now()->toDateString(),
            'amount' => '100', 'confidentiality' => 'internal',
        ];

        $as = $this->actingFresh($this->manager);
        $as->post(route('incomes.store'), [...$base, 'tax_code_id' => $foreignCode->id])->assertSessionHasErrors('tax_code_id');
        $as->post(route('incomes.store'), [...$base, 'client_id' => $foreignClient->id])->assertSessionHasErrors('client_id');
        $as->post(route('incomes.store'), [...$base, 'project_id' => $foreignProject->id])->assertSessionHasErrors('project_id');
        $as->post(route('expenses.store'), [
            'title' => 'Taxi', 'category' => 'travel', 'expense_date' => now()->toDateString(),
            'amount' => '10', 'confidentiality' => 'internal', 'tax_code_id' => $foreignCode->id,
        ])->assertSessionHasErrors('tax_code_id');

        $this->assertSame(0, $this->withinOrganization($this->meridian, fn () => Income::query()->count()));
    }

    #[Test]
    public function a_sales_only_tax_code_cannot_be_used_on_an_expense(): void
    {
        $sales = $this->makeTaxCode($this->meridian, $this->manager, 'OUT', '5', ['applies_to' => 'sales']);

        $this->actingFresh($this->manager)->post(route('expenses.store'), [
            'title' => 'Taxi', 'category' => 'travel', 'expense_date' => now()->toDateString(),
            'amount' => '10', 'confidentiality' => 'internal', 'tax_code_id' => $sales->id,
        ])->assertSessionHasErrors('tax_code_id');
    }

    #[Test]
    public function a_tax_code_in_use_cannot_be_deleted_and_a_rate_change_does_not_touch_issued_income(): void
    {
        $vat = $this->makeTaxCode($this->meridian, $this->manager, 'VAT', '7.5');
        $income = $this->makeIssuedIncome($this->meridian, $this->manager, ['tax_code_id' => $vat->id, 'amount' => '1000']);

        $this->actingFresh($this->manager)->delete(route('tax-codes.destroy', $vat))->assertSessionHasErrors();
        $this->assertNotNull($this->withinOrganization($this->meridian, fn () => TaxCode::query()->find($vat->id)));

        $this->actingFresh($this->manager)->put(route('tax-codes.update', $vat), [
            'code' => 'VAT', 'name' => 'VAT', 'rate' => '10', 'applies_to' => 'both', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('1075.00', $this->withinOrganization($this->meridian, fn () => $income->fresh()->total_amount));
    }

    #[Test]
    public function people_without_finance_grants_are_refused_every_register_and_every_write(): void
    {
        $expense = $this->makeApprovedExpense($this->meridian, $this->claimant, $this->manager);
        $income = $this->withinOrganization($this->meridian, fn () => app(IncomeService::class)->create([
            'title' => 'Draft billing', 'category' => 'contract_billing', 'income_date' => now()->toDateString(),
            'amount' => '50', 'confidentiality' => 'internal',
        ], $this->manager));
        $tin = $this->makeTin($this->meridian, $this->manager);
        $payment = $this->withinOrganization($this->meridian, fn () => app(PaymentService::class)->create([
            'direction' => 'outgoing', 'payable_type' => 'expense', 'payable_id' => $expense->id,
            'amount' => '10', 'method' => 'cash', 'confidentiality' => 'internal',
        ], $this->manager));

        $nobody = $this->makePerson($this->meridian, 'No Finance');
        $as = $this->actingFresh($nobody);

        foreach ([route('incomes.index'), route('payments.index'), route('petty-cash.index'), route('tax-codes.index'), route('incomes.create'), route('payments.create'), route('petty-cash.create')] as $url) {
            $this->assertSame(403, $as->get($url)->getStatusCode(), $url.' was reachable without a grant.');
        }

        $as->post(route('incomes.issue', $income))->assertForbidden();
        $as->post(route('payments.submit', $payment))->assertForbidden();
        $as->post(route('payments.approve', $payment))->assertForbidden();
        $as->post(route('petty-cash.disburse', $tin), ['amount' => '1', 'transacted_on' => now()->toDateString(), 'description' => 'x', 'category' => 'meals'])->assertForbidden();

        // The claimant approving their own claim is refused at the permission, before duty separation.
        $this->actingFresh($this->claimant)->post(route('expenses.approve', $expense))->assertForbidden();
        $this->assertSame('draft', $this->withinOrganization($this->meridian, fn () => Payment::query()->firstOrFail()->statusKey()));
        $this->assertSame('draft', $this->withinOrganization($this->meridian, fn () => $income->fresh()->statusKey()));
    }
}
