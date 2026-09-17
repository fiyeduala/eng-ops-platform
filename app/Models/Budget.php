<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'total_amount' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function accessAssigneeColumns(): array
    {
        return ['owner_id'];
    }

    public function registerDueColumn(): ?string
    {
        return 'ends_on';
    }

    public function registerConditionFields(): array
    {
        return ['fiscal_year', 'cost_center_id', 'total_amount'];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('line_no');
    }

    public function scopeFiscalYear(Builder $query, mixed $year): Builder
    {
        return blank($year) ? $query : $query->where('fiscal_year', (int) $year);
    }

    public function actualCosts(): Builder
    {
        $query = ProjectCost::query()->whereBetween('incurred_at', [
            $this->starts_on->startOfDay(), $this->ends_on->endOfDay(),
        ]);

        if ($this->project_id !== null) {
            $query->where('project_id', $this->project_id);
        } elseif ($this->org_unit_id !== null) {
            $unit = OrgUnit::query()->find($this->org_unit_id);
            $unitIds = $unit === null ? [$this->org_unit_id] : OrgUnit::query()
                ->where(fn (Builder $units) => $units->where('path', $unit->path)
                    ->orWhere('path', 'like', $unit->path.'/%'))
                ->pluck('id');
            $query->whereIn('org_unit_id', $unitIds);
        }

        return $query;
    }
}
