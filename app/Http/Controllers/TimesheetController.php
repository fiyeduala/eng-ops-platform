<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Models\Employee;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\WorkflowStageInstance;
use App\Services\TimesheetService;
use App\Support\OptionList;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Weekly timesheets. Filled in here, decided in My Approvals — there is no approve action.
 */
class TimesheetController extends RegisterController
{
    /** Rows offered on the grid: what is already there plus a few blank ones. */
    private const SPARE_LINES = 3;

    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly TimesheetService $timesheets,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'timesheet';
    }

    protected function modelClass(): string
    {
        return Timesheet::class;
    }

    protected function routePrefix(): string
    {
        return 'timesheets';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Timesheet::class);
        $user = $request->user();
        $query = $this->scopedQuery($user)->with(['status', 'employee']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'mine');

        if ($filters['view'] === 'mine') {
            $query->where('requester_id', $user->id);
        }

        $thisWeek = $this->timesheets->weekStart(CarbonImmutable::today());

        return view('timesheets.index', [
            'sheets' => $query->orderByDesc('period_start')->orderByDesc('id')->paginate(40)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('timesheet'),
            'filters' => $filters,
            'thisWeek' => $thisWeek,
            'current' => $user->employee ? $this->timesheets->sheetFor($user->employee->id, $thisWeek) : null,
            'canCreate' => $user->employee !== null && $user->can('create', Timesheet::class),
            'canChooseEmployee' => $user->hasPermission('timesheet.manage'),
            'employees' => $this->employeeOptions($request),
        ]);
    }

    /** Open the sheet for a week — the existing one if there is one, otherwise a new draft. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Timesheet::class);
        $user = $request->user();
        $data = $request->validate([
            'week' => ['required', 'date'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $employeeId = $this->whose($request, $data['employee_id'] ?? null);
        $weekStart = $this->timesheets->weekStart(CarbonImmutable::parse($data['week']));

        if (($existing = $this->timesheets->sheetFor($employeeId, $weekStart)) !== null) {
            // Only reveal the existing sheet to somebody who may open it.
            $this->authorize('view', $existing);

            return redirect()->route($existing->statusKey() === 'draft' && $user->can('update', $existing) ? 'timesheets.edit' : 'timesheets.show', $existing);
        }

        try {
            $sheet = $this->timesheets->create(['employee_id' => $employeeId, 'period_start' => $weekStart->toDateString()], $user);
        } catch (\DomainException $e) {
            return back()->withErrors(['week' => $e->getMessage()]);
        }

        return redirect()->route('timesheets.edit', $sheet)->with('status', $sheet->number.' is open. Fill in the hours and submit it at the end of the week.');
    }

    public function show(Request $request, Timesheet $timesheet): View
    {
        $this->authorize('view', $timesheet);
        $user = $request->user();
        $timesheet->load(['status', 'employee', 'requester', 'creator', 'orgUnit', 'entries.project', 'entries.task']);

        $instance = $timesheet->workflowInstances()
            ->with(['stageInstances.stage', 'stageInstances.approvals.user'])
            ->latest('id')
            ->first();

        return view('timesheets.show', [
            'sheet' => $timesheet,
            'days' => $this->timesheets->days($timesheet),
            'lines' => $this->lines($timesheet),
            'clocked' => $this->timesheets->attendanceMinutes($timesheet),
            'instance' => $instance,
            'myApproval' => $instance?->stageInstances
                ->where('status', WorkflowStageInstance::STATUS_PENDING)
                ->first(fn (WorkflowStageInstance $stage) => $user->can('act', $stage)),
            'timeline' => $this->activity->timelineFor($timesheet)->limit(25)->get(),
            'canEdit' => $user->can('update', $timesheet),
            'canDelete' => $user->can('delete', $timesheet),
            'canSubmit' => $user->can('submit', $timesheet),
        ]);
    }

    public function edit(Request $request, Timesheet $timesheet): View
    {
        $this->authorize('update', $timesheet);
        $user = $request->user();
        $timesheet->load(['status', 'employee', 'entries']);

        $projects = $this->timesheets->projectOptions($timesheet, $user);
        $lines = $this->lines($timesheet);

        for ($i = 0; $i < self::SPARE_LINES; $i++) {
            $lines[] = ['project_id' => null, 'task_id' => null, 'description' => null, 'hours' => []];
        }

        return view('timesheets.edit', [
            'sheet' => $timesheet,
            'days' => $this->timesheets->days($timesheet),
            'lines' => $lines,
            'projects' => $projects,
            'tasks' => $this->taskOptions(array_keys($projects)),
            'clocked' => $this->timesheets->attendanceMinutes($timesheet),
            'canSubmit' => $user->can('submit', $timesheet),
        ]);
    }

    public function update(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorize('update', $timesheet);
        $data = $request->validate([
            'lines' => ['nullable', 'array', 'max:60'],
            'lines.*.project_id' => ['nullable', 'integer'],
            'lines.*.task_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.hours' => ['nullable', 'array', 'max:7'],
            'lines.*.hours.*' => ['nullable', 'string', 'max:6'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $user = $request->user();

        try {
            $this->timesheets->saveEntries($timesheet, $data['lines'] ?? [], $user, $data['notes'] ?? null);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['lines' => $e->getMessage()]);
        }

        if ($request->boolean('submit_now')) {
            if (! $user->can('submit', $timesheet)) {
                abort(403);
            }

            try {
                $this->timesheets->submit($timesheet, $user);
            } catch (\DomainException $e) {
                return redirect()->route('timesheets.edit', $timesheet)->withErrors(['lines' => $e->getMessage()])
                    ->with('status', 'The hours were saved, but the timesheet could not be submitted.');
            }

            return redirect()->route('timesheets.show', $timesheet)->with('status', $timesheet->number.' has been submitted.');
        }

        return redirect()->route('timesheets.edit', $timesheet)->with('status', 'Hours saved.');
    }

    public function destroy(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorize('delete', $timesheet);
        $this->recordDeletion($timesheet, $request->user(), 'timesheet');
        $timesheet->delete();

        return redirect()->route('timesheets.index')->with('status', 'The draft timesheet has been deleted.');
    }

    public function submit(Request $request, Timesheet $timesheet): RedirectResponse
    {
        $this->authorize('submit', $timesheet);

        try {
            $this->timesheets->submit($timesheet, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The timesheet has been sent for approval.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The grid rows, rebuilt from the stored entries.
     *
     * @return array<int,array{project_id:?int,task_id:?int,description:?string,hours:array<string,string>,project?:mixed,task?:mixed}>
     */
    private function lines(Timesheet $sheet): array
    {
        $lines = [];

        foreach ($sheet->entries->groupBy('line') as $entries) {
            $first = $entries->first();
            $lines[] = [
                'project_id' => $first->project_id,
                'task_id' => $first->task_id,
                'description' => $first->description,
                'project' => $first->relationLoaded('project') ? $first->project : null,
                'task' => $first->relationLoaded('task') ? $first->task : null,
                'hours' => $entries->mapWithKeys(fn ($e) => [$e->work_date->toDateString() => rtrim(rtrim((string) $e->hours, '0'), '.')])->all(),
            ];
        }

        return $lines;
    }

    /**
     * Tasks on the offered projects, labelled with their project so one list serves every row.
     *
     * @param  array<int,int>  $projectIds
     * @return array<int,string>
     */
    private function taskOptions(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        return Task::query()
            ->whereIn('project_id', $projectIds)
            ->with('project:id,code,name')
            ->orderBy('project_id')
            ->orderBy('number')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (Task $t) => [$t->id => ($t->project?->code ?? $t->project?->name).' · '.$t->number.' '.$t->title])
            ->all();
    }

    /** Whose week: always your own, unless you hold `timesheet.manage` and can see the person (§84). */
    private function whose(Request $request, mixed $chosen): int
    {
        $user = $request->user();
        $own = $user->employee?->id;

        if ($chosen === null || (int) $chosen === (int) $own) {
            if ($own === null) {
                abort(403);
            }

            return (int) $own;
        }

        if (! $user->hasPermission('timesheet.manage')
            || ! $this->compiler->apply(Employee::query(), $user, 'employee.view')->whereKey($chosen)->exists()) {
            abort(403);
        }

        return (int) $chosen;
    }

    /** @return array<int,string> */
    private function employeeOptions(Request $request): array
    {
        if (! $request->user()->hasPermission('timesheet.manage')) {
            return [];
        }

        return $this->compiler->apply(Employee::query()->active(), $request->user(), 'employee.view')
            ->orderBy('first_name')->limit(500)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])->all();
    }
}
