<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engines\Configuration\SettingsRepository;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Organization;
use App\Models\PettyCashAccount;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\ExpenseService;
use App\Services\IncomeService;
use App\Services\PettyCashService;
use App\Services\TaxCodeService;

/**
 * Finance fixtures, built through the services so totals, numbers and statuses come from the same
 * code path the screens use. Must be used alongside BuildsTenants.
 */
trait BuildsFinance
{
    /**
     * Every finance ability at organization scope, spelled out rather than read from RoleTemplates
     * so that removing one from the template is caught rather than mirrored.
     *
     * @return array<string,string>
     */
    protected function financeGrants(): array
    {
        $grants = [];

        foreach ([
            'expense' => ['view', 'create', 'edit', 'delete', 'manage', 'submit', 'approve', 'reject'],
            'income' => ['view', 'create', 'edit', 'delete', 'manage', 'issue'],
            'payment' => ['view', 'create', 'edit', 'delete', 'manage', 'approve', 'pay'],
            'petty_cash' => ['view', 'create', 'edit', 'delete', 'manage', 'disburse', 'replenish'],
            'tax_code' => ['view', 'create', 'edit', 'delete'],
            'budget' => ['view'],
            'project' => ['view'],
            'org_unit' => ['view'],
            'employee' => ['view'],
            'vendor' => ['view'],
            'client' => ['view'],
            'contract' => ['view'],
        ] as $module => $abilities) {
            foreach ($abilities as $ability) {
                $grants[$module.'.'.$ability] = 'organization';
            }
        }

        return $grants;
    }

    /** What every employee gets for their own claims. */
    protected function claimantGrants(): array
    {
        return [
            'expense.view' => 'own',
            'expense.create' => 'own',
            'expense.edit' => 'own',
            'expense.submit' => 'own',
        ];
    }

    protected function financeSetting(Organization $organization, string $key, mixed $value): void
    {
        $this->withinOrganization(
            $organization,
            fn () => app(SettingsRepository::class)->set($key, $value, $organization->id, 'finance')
        );
    }

    protected function makeTaxCode(Organization $organization, User $actor, string $code, string $rate, array $attributes = []): TaxCode
    {
        return $this->withinOrganization($organization, fn () => app(TaxCodeService::class)->create([
            'code' => $code,
            'name' => $code.' '.$rate.'%',
            'rate' => $rate,
            'applies_to' => 'both',
            'is_recoverable' => false,
            'is_active' => true,
            ...$attributes,
        ], $actor));
    }

    protected function makeExpense(Organization $organization, User $actor, array $attributes = []): Expense
    {
        /** @var Expense */
        return $this->withinOrganization($organization, fn () => app(ExpenseService::class)->create([
            'title' => 'Site visit travel',
            'category' => 'travel',
            'expense_date' => now()->toDateString(),
            'amount' => '1000.00',
            'confidentiality' => 'internal',
            ...$attributes,
        ], $actor));
    }

    /** Raised by `$claimant`, submitted, and approved by `$approver`. */
    protected function makeApprovedExpense(Organization $organization, User $claimant, User $approver, array $attributes = []): Expense
    {
        $expense = $this->makeExpense($organization, $claimant, $attributes);

        return $this->withinOrganization($organization, function () use ($expense, $claimant, $approver) {
            $service = app(ExpenseService::class);
            $service->submit($expense, $claimant);

            return $service->approve($expense, $approver);
        });
    }

    protected function makeIssuedIncome(Organization $organization, User $actor, array $attributes = []): Income
    {
        return $this->withinOrganization($organization, function () use ($actor, $attributes) {
            $service = app(IncomeService::class);
            /** @var Income $income */
            $income = $service->create([
                'title' => 'Interim valuation 3',
                'category' => 'contract_billing',
                'income_date' => now()->toDateString(),
                'amount' => '500.00',
                'confidentiality' => 'internal',
                ...$attributes,
            ], $actor);

            return $service->issue($income, $actor);
        });
    }

    protected function makeTin(Organization $organization, User $actor, array $attributes = []): PettyCashAccount
    {
        return $this->withinOrganization($organization, fn () => app(PettyCashService::class)->createAccount([
            'code' => 'PC-SITE',
            'name' => 'Site office tin',
            'float_amount' => '500.00',
            'is_active' => true,
            'confidentiality' => 'internal',
            ...$attributes,
        ], $actor));
    }
}
