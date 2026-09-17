<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one person was granted of one leave type in one year.
 *
 * Days TAKEN are deliberately not a column — see the leave migration. LeaveService works them out
 * from the requests, so a cancelled request gives its days back without anything having to
 * remember to.
 */
class LeaveBalance extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled' => 'decimal:2',
            'carried_over' => 'decimal:2',
            'adjustment' => 'decimal:2',
        ];
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
        return [];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class)->withTrashed();
    }

    /** Everything granted for the year, before anything is taken. */
    public function granted(): string
    {
        return (string) BigDecimal::of($this->entitled ?? '0')
            ->plus($this->carried_over ?? '0')
            ->plus($this->adjustment ?? '0')
            ->toScale(2);
    }

    public function displayName(): string
    {
        return ($this->leaveType?->name ?? 'Leave').' '.$this->year;
    }
}
