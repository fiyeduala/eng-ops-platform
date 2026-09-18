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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What THIS person gets that the pay structure does not give everybody.
 *
 * A site allowance while they are on the job; a loan repayment until it is repaid. Components
 * marked `applies_to_all` need no row here at all — this table is only for the exceptions, which
 * keeps it small and makes "who is on this allowance" a query rather than a report.
 *
 * EFFECTIVE-DATED, for the same reason salaries are. A deduction that ended in June must not
 * reappear when somebody reruns June, and a system that expressed "no longer applies" by deleting
 * the row would do exactly that — as well as losing the fact that it ever applied.
 *
 * `amount` and `rate` are OVERRIDES, and null is the ordinary case. A null override means "use the
 * component's own figure", so the row then says only that this person has this component at all.
 * Copying the component's rate into every assignment would mean a rate change had to be chased
 * across every employee who held it.
 */
class EmployeePayComponent extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    // ------------------------------------------------------------------ access

    /** Read through the person it applies to, on the same terms as their salary. */
    public function accessParentRelation(): ?string
    {
        return 'employee';
    }

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

    // ------------------------------------------------------------------ behaviour

    /** Whether this assignment was live on a given day. */
    public function coversDate(Carbon $date): bool
    {
        if (! $this->is_active || $this->effective_from->isAfter($date)) {
            return false;
        }

        return $this->effective_to === null || ! $this->effective_to->isBefore($date);
    }

    /** @param  Builder<static>  $query */
    public function scopeInForceOn(Builder $query, Carbon $date): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }
}
