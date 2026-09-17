<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCenter;
use App\Models\User;
use App\Services\Concerns\ManagesLineItems;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BudgetService extends RegisterService
{
    use ManagesLineItems;

    protected function entityType(): string
    {
        return 'budget';
    }

    protected function modelClass(): string
    {
        return Budget::class;
    }

    protected function itemClass(): string
    {
        return BudgetLine::class;
    }

    protected function itemForeignKey(): string
    {
        return 'budget_id';
    }

    protected function protectedAttributes(): array
    {
        return ['status_id', 'total_amount', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at'];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Budget $record */
        $this->applyCostCenter($record);
        $record->currency = $actor->organization?->currency;
        $record->fiscal_year = $record->fiscal_year ?: (int) $record->starts_on?->format('Y');
    }

    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Budget $record */
        $this->syncItems($record, (array) ($attributes['items'] ?? []), $actor, false);
        $this->recalculate($record);
    }

    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Budget $record */
        if ($record->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft budget can be changed.');
        }

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $attributes = $this->applyCostCenterAttributes($attributes);
            $updated = parent::update($record, $attributes, $actor);
            $this->syncItems($updated, (array) ($attributes['items'] ?? []), $actor);
            $this->recalculate($updated);

            return $updated;
        });
    }

    protected function itemAttributes(array $row, Model $parent): array
    {
        return [
            'category' => str((string) ($row['category'] ?? 'other'))->snake()->toString(),
            'description' => trim((string) ($row['description'] ?? '')),
            'allocated_amount' => (string) BigDecimal::of($row['allocated_amount'] ?? '0')
                ->toScale(2, RoundingMode::HALF_UP),
        ];
    }

    public function submit(Budget $budget, User $actor): Budget
    {
        if ($budget->statusKey() !== 'draft' || $budget->items()->doesntExist()) {
            throw new \DomainException('A draft budget needs at least one line before it can be submitted.');
        }

        return $this->changeState($budget, 'submitted', $actor, 'submitted', 'submitted_by', 'submitted_at');
    }

    public function approve(Budget $budget, User $actor): Budget
    {
        if ($budget->statusKey() !== 'submitted') {
            throw new \DomainException('Only a submitted budget can be approved.');
        }

        return $this->changeState($budget, 'approved', $actor, 'approved', 'approved_by', 'approved_at');
    }

    public function returnForAmendment(Budget $budget, User $actor): Budget
    {
        if ($budget->statusKey() !== 'submitted') {
            throw new \DomainException('Only a submitted budget can be returned.');
        }

        return $this->changeState($budget, 'draft', $actor, 'returned', 'submitted_by', 'submitted_at', true);
    }

    public function reject(Budget $budget, User $actor): Budget
    {
        if ($budget->statusKey() !== 'submitted') {
            throw new \DomainException('Only a submitted budget can be rejected.');
        }

        return $this->changeState($budget, 'rejected', $actor, 'rejected');
    }

    public function close(Budget $budget, User $actor): Budget
    {
        if ($budget->statusKey() !== 'approved') {
            throw new \DomainException('Only an approved budget can be closed.');
        }

        return $this->changeState($budget, 'closed', $actor, 'closed');
    }

    private function changeState(
        Budget $budget,
        string $status,
        User $actor,
        string $verb,
        ?string $by = null,
        ?string $at = null,
        bool $clear = false,
    ): Budget {
        return DB::transaction(function () use ($budget, $status, $actor, $verb, $by, $at, $clear) {
            $this->moveTo($budget, $status);
            if ($by !== null && $at !== null) {
                $budget->forceFill([$by => $clear ? null : $actor->id, $at => $clear ? null : now()]);
            }
            $budget->save();
            $this->activity->record($budget, $verb, $actor->name.' '.$verb.' '.$budget->number.'.', [], $actor, $budget->project_id);

            return $budget;
        });
    }

    private function recalculate(Budget $budget): void
    {
        $total = $budget->items()->get()->reduce(
            fn (BigDecimal $sum, BudgetLine $line) => $sum->plus($line->allocated_amount),
            BigDecimal::zero(),
        )->toScale(2, RoundingMode::HALF_UP);
        $budget->forceFill(['total_amount' => (string) $total])->save();
    }

    private function applyCostCenter(Budget $budget): void
    {
        if ($budget->cost_center_id === null) {
            return;
        }

        $center = CostCenter::query()->findOrFail($budget->cost_center_id);
        foreach (['project_id', 'org_unit_id'] as $column) {
            if ($budget->getAttribute($column) !== null && $center->getAttribute($column) !== null
                && (int) $budget->getAttribute($column) !== (int) $center->getAttribute($column)) {
                throw new \DomainException('The budget scope must match its cost center.');
            }
            if ($budget->getAttribute($column) === null) {
                $budget->setAttribute($column, $center->getAttribute($column));
            }
        }
    }

    private function applyCostCenterAttributes(array $attributes): array
    {
        if (blank($attributes['cost_center_id'] ?? null)) {
            return $attributes;
        }

        $center = CostCenter::query()->findOrFail((int) $attributes['cost_center_id']);
        foreach (['project_id', 'org_unit_id'] as $column) {
            if (! blank($attributes[$column] ?? null) && $center->getAttribute($column) !== null
                && (int) $attributes[$column] !== (int) $center->getAttribute($column)) {
                throw new \DomainException('The budget scope must match its cost center.');
            }
            $attributes[$column] ??= $center->getAttribute($column);
        }

        return $attributes;
    }
}
