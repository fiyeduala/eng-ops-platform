<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Money spent that did not come through a purchase order: a hotel, a courier, a site permit fee.
 *
 * APPROVAL IS WHAT MAKES IT A COST. On approval the service posts one row to the project-cost
 * ledger, with this expense as its source, so the budget page counts it the same way it counts an
 * accepted delivery (§90). Payment afterwards settles the claim; it does not cost the project a
 * second time.
 *
 * THE CATEGORY IS A BUDGET CATEGORY. An expense filed under "travel" is compared against the budget
 * line called "travel", which is why the two lists share keys rather than meaning.
 */
class Expense extends Model
{
    use RegisterRecord;

    public const DEFAULT_CATEGORIES = [
        'Travel', 'Accommodation', 'Meals', 'Fuel', 'Fees and permits',
        'Courier', 'Repairs', 'Consumables', 'Professional services', 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_recoverable' => 'boolean',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * The claimant reaches their own claim whoever typed it in for them. The baseline grants the
     * expense module at `own`, so the claimant has to be an owner column, not only an assignee.
     */
    public function accessOwnerColumns(): array
    {
        return ['created_by', 'claimant_id'];
    }

    public function accessAssigneeColumns(): array
    {
        return ['claimant_id'];
    }

    public function registerConditionFields(): array
    {
        return ['category', 'total_amount', 'project_id', 'org_unit_id'];
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }

    public static function workflowEntityLabel(): string
    {
        return 'Expenses';
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class)->withTrashed();
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return MorphOne<ProjectCost,$this> */
    public function projectCost(): MorphOne
    {
        return $this->morphOne(ProjectCost::class, 'source');
    }

    /** @return MorphMany<Payment,$this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function payeeLabel(): string
    {
        return $this->vendor?->name ?? $this->payee_name ?? $this->claimant?->name ?? 'Not named';
    }

    /**
     * What the project actually bears.
     *
     * Recoverable tax is money the company will claim back, so it is not a cost; irrecoverable tax
     * is, and leaving it out would make every budget in a company that cannot reclaim look better
     * than it is.
     */
    public function costAmount(): string
    {
        return (string) ($this->tax_recoverable ? $this->amount : $this->total_amount);
    }

    public function outstanding(): string
    {
        return (string) BigDecimal::of($this->total_amount ?? '0')->minus($this->paid_amount ?? '0');
    }

    public function isPayable(): bool
    {
        return $this->statusKey() === 'approved' && BigDecimal::of($this->outstanding())->isPositive();
    }
}
