<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Overtime: asked for before the work, decided by a route, then the hours actually worked recorded.
 *
 * Payroll (part 5) pays `worked_hours` on approved requests and nothing else. That column can never
 * exceed what was approved — working longer than agreed is a new request, not a bigger number.
 */
class OvertimeService extends RoutedTimeService
{
    /** Statuses that hold a day: a second request for the same person and date is refused. */
    public const COMMITTED = ['submitted', 'approved'];

    protected function entityType(): string
    {
        return 'overtime_request';
    }

    protected function modelClass(): string
    {
        return OvertimeRequest::class;
    }

    protected function noun(): string
    {
        return 'overtime request';
    }

    protected function protectedAttributes(): array
    {
        return [...parent::protectedAttributes(), 'worked_hours'];
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        parent::beforeCreate($record, $attributes, $actor);

        /** @var OvertimeRequest $record */
        $this->checkShape($record);
    }

    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var OvertimeRequest $record */
        $this->requireDraft($record, 'changed');
        unset($attributes['employee_id']);

        return DB::transaction(function () use ($record, $attributes, $actor) {
            $record->fill($this->safeAttributes($attributes));
            $this->checkShape($record);

            return parent::update($record, [], $actor);
        });
    }

    public function submit(OvertimeRequest $request, User $actor): OvertimeRequest
    {
        $this->requireDraft($request, 'submitted');

        return DB::transaction(function () use ($request, $actor) {
            Employee::query()->lockForUpdate()->findOrFail($request->employee_id);
            $this->checkShape($request);

            $clash = OvertimeRequest::query()
                ->where('employee_id', $request->employee_id)
                ->whereKeyNot($request->getKey())
                ->whereDate('work_date', $request->work_date->toDateString())
                ->whereIn('status_id', $this->committedStatusIds())
                ->first();

            if ($clash !== null) {
                throw new \DomainException('There is already overtime for this day in '.$clash->number.'. Change that request instead.');
            }

            $this->route($request, $actor, $actor->name.' asked for '.$request->hours.'h of overtime on '.$request->work_date->format('j M Y').'.');

            return $request;
        });
    }

    /**
     * Record how many of the approved hours were worked. May be corrected until payroll locks it.
     */
    public function recordWorked(OvertimeRequest $request, string $hours, User $actor): OvertimeRequest
    {
        if ($request->statusKey() !== 'approved') {
            throw new \DomainException('Hours can only be recorded against approved overtime.');
        }

        if ($request->work_date->gt(CarbonImmutable::today())) {
            throw new \DomainException('Record the hours on or after '.$request->work_date->format('j M Y').'.');
        }

        try {
            $worked = BigDecimal::of(str_replace(',', '.', trim($hours)));
        } catch (MathException) {
            throw new \DomainException('Give the hours as a number.');
        }

        if ($worked->isNegative() || $worked->getScale() > 2) {
            throw new \DomainException('Hours must be zero or more, to two decimal places.');
        }

        if ($worked->isGreaterThan($request->hours)) {
            throw new \DomainException('Only '.$request->hours.'h were approved. Ask for more overtime rather than recording extra here.');
        }

        $before = $request->worked_hours === null ? null : (string) $request->worked_hours;
        $request->worked_hours = (string) $worked->toScale(2);
        $request->updated_by = $actor->getKey();
        $request->save();

        $this->activity->record(
            $request,
            'hours_recorded',
            $actor->name.' recorded '.$request->worked_hours.'h worked on '.$this->label($request).'.',
            ['worked_hours' => ['from' => $before, 'to' => (string) $request->worked_hours]],
            $actor,
            $this->projectIdOf($request),
        );

        return $request;
    }

    public function cancel(OvertimeRequest $request, User $actor, string $reason): OvertimeRequest
    {
        if (! in_array($request->statusKey(), self::COMMITTED, true)) {
            throw new \DomainException('This overtime request is already closed.');
        }

        if ($request->worked_hours !== null) {
            throw new \DomainException('Hours have been recorded against this overtime, so it cannot be cancelled. Record 0 hours instead.');
        }

        if (blank($reason)) {
            throw new \DomainException('Say why the overtime is being cancelled.');
        }

        return DB::transaction(function () use ($request, $actor, $reason) {
            $request->forceFill([
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);
            $this->moveTo($request, 'cancelled');
            $request->save();

            $this->closeRoute($request, $actor, $reason);

            $this->activity->record($request, 'cancelled', $actor->name.' cancelled '.$this->label($request).'.', ['reason' => $reason], $actor);

            return $request;
        });
    }

    /** @return array<int,int> */
    public function committedStatusIds(): array
    {
        return $this->statuses->statusesFor('overtime_request')
            ->whereIn('key', self::COMMITTED)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function checkShape(OvertimeRequest $request): void
    {
        if ($request->work_date === null) {
            throw new \DomainException('Give the day the overtime will be worked.');
        }

        try {
            $hours = BigDecimal::of((string) $request->hours);
        } catch (MathException) {
            throw new \DomainException('Give the hours as a number.');
        }

        if (! $hours->isPositive() || $hours->getScale() > 2 || $hours->isGreaterThan(24)) {
            throw new \DomainException('Overtime must be more than 0 and at most 24 hours, to two decimal places.');
        }
    }
}
