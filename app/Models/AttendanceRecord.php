<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Models\Concerns\SeenByLineManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One shift: when somebody arrived and when they left.
 *
 * Not a register and not approved — see the migration. Governed by `attendance.*`.
 */
class AttendanceRecord extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, Scopeable, SeenByLineManager, SoftDeletes;

    public const SOURCE_SELF = 'self';

    public const SOURCE_MANUAL = 'manual';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'minutes' => 'integer',
            'clock_in_latitude' => 'decimal:7',
            'clock_in_longitude' => 'decimal:7',
            'clock_out_latitude' => 'decimal:7',
            'clock_out_longitude' => 'decimal:7',
            'adjusted_at' => 'datetime',
        ];
    }

    public static function permissionModule(): string
    {
        return 'attendance';
    }

    // ------------------------------------------------------------------ access

    public function accessOrgUnitColumn(): ?string
    {
        return 'org_unit_id';
    }

    public function accessProjectColumn(): ?string
    {
        return 'project_id';
    }

    public function accessOwnerColumns(): array
    {
        return ['user_id'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function applyAssignedScope(Builder $query, User $user): void
    {
        $this->applyLineManagerScope($query, $user);
    }

    // ------------------------------------------------------------------ scopes

    /** @param  Builder<static>  $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('clock_out_at');
    }

    // ------------------------------------------------------------------ relations

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    // ------------------------------------------------------------------ presentation

    public function isOpen(): bool
    {
        return $this->clock_out_at === null;
    }

    public function hoursLabel(): string
    {
        if ($this->minutes === null) {
            return '—';
        }

        return intdiv($this->minutes, 60).'h '.str_pad((string) ($this->minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }

    public function hasLocation(): bool
    {
        return $this->clock_in_latitude !== null || $this->clock_out_latitude !== null;
    }

    public function displayName(): string
    {
        $this->loadMissing('employee');

        return ($this->employee?->fullName() ?? 'Attendance').', '.$this->work_date?->format('j M Y');
    }
}
