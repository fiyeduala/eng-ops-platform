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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A kind of leave the company grants — annual, sick, study, unpaid — on its own terms (§47).
 *
 * Nothing in the application knows what any of these mean. The only behaviour attached to a type
 * is what its columns say: whether it is counted against a balance (`days_per_year` not null), how
 * much of an unused balance rolls into next year, and whether it goes for approval at all.
 */
class LeaveType extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'decimal:2',
            'carry_over_limit' => 'decimal:2',
            'max_consecutive_days' => 'integer',
            'min_notice_days' => 'integer',
            'is_paid' => 'boolean',
            'requires_approval' => 'boolean',
            'allow_half_days' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** A company-wide configuration row: no department, no project, no owner. */
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

    /** The name of a leave type is a label everybody who may request leave needs to read. */
    public function accessOrganizationWideAbilities(): array
    {
        return ['leave.view'];
    }

    public function tracksBalance(): bool
    {
        return $this->days_per_year !== null;
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return $this->name.' ('.$this->code.')';
    }
}
