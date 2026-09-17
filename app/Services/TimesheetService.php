<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\WorkflowEngine;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Weekly timesheets: opened, filled in, routed, decided.
 *
 * THE TOTAL IS NEVER TAKEN FROM THE FORM. Entries are validated and summed here every time the grid
 * is saved and again on submit (§84).
 *
 * HOURS GO ONLY TO PROJECTS THE PERSON IS ON. A project is offered — and accepted — when the owner
 * of the sheet is a current member of it or can already see it. Posting to a project id typed into
 * the request is refused, which is what stops a timesheet becoming a way to learn a project's name
 * or to charge hours to a job somebody has nothing to do with.
 */
class TimesheetService extends RoutedTimeService
{
    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        SettingsRepository $settings,
        WorkflowEngine $workflow,
        private readonly ScopeCompiler $compiler,
    ) {
        parent::__construct($numbers, $statuses, $activity, $settings, $workflow);
    }

    protected function entityType(): string
    {
        return 'timesheet';
    }

    protected function modelClass(): string
    {
        return Timesheet::class;
    }

    protected function noun(): string
    {
        return 'timesheet';
    }

    protected function protectedAttributes(): array
    {
        return [...parent::protectedAttributes(), 'total_hours', 'period_end', 'project_id'];
    }

    // ================================================================== the week

    /** The first day of the company week that contains this date. */
    public function weekStart(CarbonInterface $day): CarbonImmutable
    {
        $first = strtolower($this->settings->string('work.week_starts_on', 'monday'));
        $day = CarbonImmutable::parse($day->toDateString());

        for ($i = 0; $i < 7; $i++) {
            $candidate = $day->subDays($i);

            if (strtolower($candidate->englishDayOfWeek) === $first) {
                return $candidate;
            }
        }

        return $day->startOfWeek();
    }

    /** @return array<int,CarbonImmutable> */
    public function days(Timesheet $sheet): array
    {
        $start = CarbonImmutable::parse($sheet->period_start->toDateString());

        return array_map(fn (int $i) => $start->addDays($i), range(0, 6));
    }

    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        parent::beforeCreate($record, $attributes, $actor);

        /** @var Timesheet $record */
        if ($record->period_start === null) {
            throw new \DomainException('Choose the week.');
        }

        $start = $this->weekStart($record->period_start);
        $record->period_start = $start;
        $record->period_end = $start->addDays(6);
        $record->total_hours = '0.00';

        if ($this->sheetFor((int) $record->employee_id, $start) !== null) {
            throw new \DomainException('There is already a timesheet for the week of '.$start->format('j M Y').'.');
        }
    }

    /** The live sheet for a person and week — anything but a rejected one, which closed the week. */
    public function sheetFor(int $employeeId, CarbonInterface $weekStart): ?Timesheet
    {
        return Timesheet::query()
            ->where('employee_id', $employeeId)
            ->whereDate('period_start', $weekStart->toDateString())
            ->where(fn ($q) => $q->whereNull('status_id')->orWhereNotIn('status_id', $this->statusIds(['rejected'])))
            ->first();
    }

    // ================================================================== entries

    /**
     * Replace the grid.
     *
     * @param  array<int,array{project_id?:mixed,task_id?:mixed,description?:mixed,hours?:array<string,mixed>}>  $lines
     */
    public function saveEntries(Timesheet $sheet, array $lines, User $actor, ?string $notes = null): Timesheet
    {
        $this->requireDraft($sheet, 'changed');

        return DB::transaction(function () use ($sheet, $lines, $actor, $notes) {
            $days = collect($this->days($sheet))->keyBy(fn (CarbonImmutable $d) => $d->toDateString());
            $allowedProjects = array_flip($this->allowedProjectIds($sheet, $actor));
            $max = BigDecimal::of((string) $this->settings->int('timesheet.max_hours_per_day', 24));

            $rows = [];
            $perDay = [];
            $total = BigDecimal::zero();

            foreach (array_values($lines) as $index => $line) {
                $projectId = blank($line['project_id'] ?? null) ? null : (int) $line['project_id'];
                $taskId = blank($line['task_id'] ?? null) ? null : (int) $line['task_id'];
                $description = trim((string) ($line['description'] ?? '')) ?: null;
                $hours = (array) ($line['hours'] ?? []);

                if ($projectId !== null && ! isset($allowedProjects[$projectId])) {
                    throw new \DomainException('Line '.($index + 1).': hours can only be booked to a project this person works on.');
                }

                if ($taskId !== null) {
                    $task = Task::query()->find($taskId);

                    if ($task === null || $projectId === null || (int) $task->project_id !== $projectId) {
                        throw new \DomainException('Line '.($index + 1).': the task must belong to the project on that line.');
                    }
                }

                foreach ($hours as $date => $value) {
                    if (blank($value)) {
                        continue;
                    }

                    if (! $days->has((string) $date)) {
                        throw new \DomainException('Line '.($index + 1).': '.$date.' is not in this week.');
                    }

                    try {
                        $amount = BigDecimal::of(str_replace(',', '.', (string) $value));
                    } catch (MathException) {
                        throw new \DomainException('Line '.($index + 1).': "'.$value.'" is not a number of hours.');
                    }

                    if ($amount->isZero()) {
                        continue;
                    }

                    if ($amount->isNegative() || $amount->getScale() > 2 || $amount->isGreaterThan(24)) {
                        throw new \DomainException('Line '.($index + 1).': hours must be between 0 and 24, to two decimal places.');
                    }

                    $perDay[$date] = ($perDay[$date] ?? BigDecimal::zero())->plus($amount);

                    if ($perDay[$date]->isGreaterThan($max)) {
                        throw new \DomainException('More than '.$max.' hours are booked on '.CarbonImmutable::parse($date)->format('D j M').'.');
                    }

                    $total = $total->plus($amount);
                    $rows[] = [
                        'organization_id' => $sheet->organization_id,
                        'timesheet_id' => $sheet->getKey(),
                        'work_date' => $date,
                        'project_id' => $projectId,
                        'task_id' => $taskId,
                        'description' => $description === null ? null : mb_substr($description, 0, 255),
                        'hours' => (string) $amount->toScale(2),
                        'line' => $index,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            $before = (string) $sheet->total_hours;
            $sheet->entries()->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                TimesheetEntry::query()->insert($chunk);
            }

            $sheet->total_hours = (string) $total->toScale(2);
            $sheet->notes = $notes;
            $sheet->updated_by = $actor->getKey();
            $sheet->save();

            $this->activity->record(
                $sheet,
                'updated',
                $actor->name.' updated the hours on '.$this->label($sheet).' ('.$sheet->total_hours.'h).',
                ['total_hours' => ['from' => $before, 'to' => (string) $sheet->total_hours]],
                $actor,
            );

            return $sheet->unsetRelation('entries');
        });
    }

    /**
     * Projects the sheet's owner may book to: current memberships, plus anything they can already see.
     *
     * Read as the OWNER, not the actor, so HR filling in a sheet for somebody cannot charge their
     * hours to a project that person has never been near. A person with no sign-in has no reach of
     * their own; HR's reach stands in for it.
     *
     * @return array<int,int>
     */
    public function allowedProjectIds(Timesheet|Employee $subject, User $actor): array
    {
        $employee = $subject instanceof Timesheet ? $subject->employee : $subject;
        $owner = $employee?->user_id === null ? null : User::query()->find($employee->user_id);
        $reader = $owner ?? $actor;

        $members = ProjectMember::query()->current()->where('user_id', $reader->getKey())->pluck('project_id');
        $visible = $this->compiler->apply(Project::query(), $reader, 'project.view')->pluck('projects.id');

        return $members->merge($visible)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int,string> id => code and name */
    public function projectOptions(Timesheet|Employee $subject, User $actor): array
    {
        return Project::query()
            ->whereIn('id', $this->allowedProjectIds($subject, $actor))
            ->orderBy('name')
            ->limit(300)
            ->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => trim(($p->code ? $p->code.' — ' : '').$p->name)])
            ->all();
    }

    /**
     * Clocked minutes per day for the week, from attendance — shown beside the grid as a cross-check.
     *
     * @return array<string,int>
     */
    public function attendanceMinutes(Timesheet $sheet): array
    {
        return AttendanceRecord::query()
            ->where('employee_id', $sheet->employee_id)
            ->whereBetween('work_date', [$sheet->period_start->toDateString(), $sheet->period_end->toDateString()])
            ->whereNotNull('minutes')
            ->get(['work_date', 'minutes'])
            ->groupBy(fn (AttendanceRecord $r) => $r->work_date->toDateString())
            ->map(fn ($rows) => (int) $rows->sum('minutes'))
            ->all();
    }

    // ================================================================== submitting

    public function submit(Timesheet $sheet, User $actor): Timesheet
    {
        $this->requireDraft($sheet, 'submitted');

        return DB::transaction(function () use ($sheet, $actor) {
            Employee::query()->lockForUpdate()->findOrFail($sheet->employee_id);

            $total = BigDecimal::of((string) TimesheetEntry::query()->where('timesheet_id', $sheet->getKey())->sum('hours'))->toScale(2);

            if (! $total->isPositive()) {
                throw new \DomainException('There are no hours on this timesheet.');
            }

            $sheet->total_hours = (string) $total;

            $this->route($sheet, $actor, $actor->name.' submitted '.$this->label($sheet).' ('.$sheet->total_hours.'h).');

            return $sheet;
        });
    }

    /** @param  array<int,string>  $keys  @return array<int,int> */
    private function statusIds(array $keys): array
    {
        return $this->statuses->statusesFor('timesheet')->whereIn('key', $keys)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
