<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the working, frozen at the moment it was calculated.
 *
 * EVERYTHING NEEDED TO EXPLAIN THE FIGURE IS COPIED HERE. The code, the name, the kind, the method,
 * the basis, the rate and what the rate was applied to — none of it is read back from the live pay
 * structure. That is what lets a payslip from three years ago render correctly against a structure
 * that has since been rewritten, and it is why `pay_component_id` may be null while `code` and
 * `name` never are: the component can be deleted outright and the payslip still explains itself.
 *
 * This is the same discipline `tax_codes` established in Phase 5 for VAT, and it matters more here.
 * A tax rate that changes in April must not move a figure on a payslip issued in March — and
 * because the line carries its own rate, it cannot, by construction rather than by a guard somebody
 * has to remember to write.
 *
 * `basis_amount` is the part people forget. Without it a payslip shows a number and no working, and
 * the first question anybody asks about a deduction is what it was taken from.
 */
class PayslipLine extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'quantity' => 'decimal:2',
            'basis_amount' => 'decimal:2',
            'amount' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /** May be null: the component it came from can be deleted without harming the record. */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
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
     * How the figure was arrived at, in words, for the payslip itself.
     *
     * Built from the copied columns and never from the live component, for the same reason the
     * amount is: the explanation has to still be true years later.
     */
    public function workingLabel(): string
    {
        return match ($this->method) {
            'percent' => rtrim(rtrim((string) $this->rate, '0'), '.').'% of '.number_format((float) $this->basis_amount, 2),
            'hourly' => number_format((float) $this->quantity, 2).' h at '.number_format((float) $this->rate, 2),
            'banded' => 'Banded on '.number_format((float) $this->basis_amount, 2),
            default => 'Fixed',
        };
    }
}
