<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing that happens to somebody's pay, described by the company that pays it (§47).
 *
 * THE PLATFORM DOES NOT KNOW WHAT ANY OF THESE ARE. "PAYE", "National Insurance", "Pension", "NHF",
 * "Housing allowance" and "Car loan repayment" are rows somebody typed. The engine reads four
 * fields — kind, method, basis, rate — and can compute every one of them without knowing which
 * country it is in or which of them is a tax. That is the entire §47 argument, and it is why there
 * is no `country` column anywhere near this table: a country is not a thing the code may branch on.
 *
 * `relief` is the kind that looks odd and is not. Most tax systems let some part of income escape
 * tax without that money moving anywhere — a personal allowance, a consolidated relief. Without
 * this kind a company would have to model it as a negative earning and would see the wrong gross on
 * every payslip.
 *
 * `sequence` IS THE CALCULATION ORDER, and it belongs to the company. Whether pension is deducted
 * before or after tax is a question with different answers in different places, and the platform is
 * in no position to hold an opinion about it. Ordering the components IS expressing that rule.
 *
 * BASIC SALARY IS A COMPONENT TOO — method `percent`, basis `basic`, rate 100. The engine seeds one
 * figure from the salary row and everything on the payslip after that, basic included, is a row
 * somebody configured. The alternative was to special-case basic as an implicit taxable, pensionable
 * earning, which would have hard-coded two of the most jurisdiction-specific decisions in payroll
 * into the one line nobody would think to look at. A company where basic is not fully pensionable
 * unticks a box; it does not need us.
 */
class PayComponent extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    /** What the component does to the payslip. */
    public const KINDS = [
        'earning' => 'Earning',
        'deduction' => 'Deduction',
        'employer_cost' => 'Employer cost',
        'relief' => 'Tax relief',
    ];

    /** How the figure is arrived at. */
    public const METHODS = [
        'fixed' => 'Fixed amount',
        'percent' => 'Percentage of a basis',
        'hourly' => 'Rate per hour',
        'banded' => 'Progressive bands',
    ];

    /**
     * What the method is applied to.
     *
     * Resolved at the moment this component is reached in `sequence` order, never before — which is
     * what lets a deduction reduce the taxable figure that a later component reads.
     */
    public const BASES = [
        'none' => 'Not applicable',
        'basic' => 'Basic salary',
        'gross' => 'Gross pay',
        'taxable_gross' => 'Taxable pay (after reliefs)',
        'pensionable' => 'Pensionable pay',
        'net' => 'Net pay so far',
        'overtime_hours' => 'Approved overtime hours',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'minimum_amount' => 'decimal:2',
            'maximum_amount' => 'decimal:2',
            'is_taxable' => 'boolean',
            'is_pensionable' => 'boolean',
            'reduces_taxable' => 'boolean',
            'is_prorated' => 'boolean',
            'applies_to_all' => 'boolean',
            'is_active' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    public function bands(): HasMany
    {
        return $this->hasMany(PayComponentBand::class)->orderBy('sequence')->orderBy('from_amount');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeePayComponent::class);
    }

    /**
     * Every payslip line ever calculated from this component.
     *
     * Kept so the policy can refuse to delete a component that has been used. The lines themselves
     * do not need it — each one copied the code, name, kind, method, basis and rate it was
     * calculated under, so an old payslip still explains itself after the component is retired or
     * its rate is changed.
     */
    public function payslipLines(): HasMany
    {
        return $this->hasMany(PayslipLine::class);
    }

    /** A company-wide configuration row: it has no department and no project. */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function isEarning(): bool
    {
        return $this->kind === 'earning';
    }

    public function isDeduction(): bool
    {
        return $this->kind === 'deduction';
    }

    public function isEmployerCost(): bool
    {
        return $this->kind === 'employer_cost';
    }

    public function isRelief(): bool
    {
        return $this->kind === 'relief';
    }

    /**
     * Whether this component reduces what the employee is actually paid.
     *
     * Only deductions do. An employer cost is real money leaving the company and never touches net
     * pay, and a relief moves no money at all — conflating either with a deduction is the classic
     * way a payroll system underpays everybody by the employer's own pension contribution.
     */
    public function reducesNetPay(): bool
    {
        return $this->kind === 'deduction';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function basisLabel(): string
    {
        return self::BASES[$this->basis] ?? $this->basis;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The order the engine walks them in.
     *
     * Ties break on id so that two components a company gave the same sequence still calculate in a
     * stable order — a payroll run that produced different figures on a second attempt because two
     * rows sorted arbitrarily would be very hard to believe and even harder to diagnose.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInCalculationOrder(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('id');
    }
}
