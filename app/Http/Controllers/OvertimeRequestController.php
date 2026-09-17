<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\OvertimeRequestRequest;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Services\OvertimeService;
use App\Services\TimesheetService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Overtime, asked for before it is worked and decided in My Approvals. Approved and worked hours are
 * what payroll will read in part 5 — hours only, no money here.
 */
class OvertimeRequestController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly OvertimeService $overtime,
        private readonly TimesheetService $timesheets,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'overtime_request';
    }

    protected function modelClass(): string
    {
        return OvertimeRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'overtime';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', OvertimeRequest::class);
        $user = $request->user();
        $query = $this->scopedQuery($user, 'overtime.view')->with(['status', 'employee', 'project']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'mine');

        if ($filters['view'] === 'mine') {
            $query->where('requester_id', $user->id);
        }

        return view('overtime.index', [
            'requests' => $query->orderByDesc('work_date')->orderByDesc('id')->paginate(40)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('overtime_request'),
            'filters' => $filters,
            'canCreate' => $user->can('create', OvertimeRequest::class),
        ]);
    }

    public function show(Request $request, OvertimeRequest $overtime): View
    {
        $this->authorize('view', $overtime);
        $user = $request->user();
        $overtime->load(['status', 'employee', 'requester', 'project', 'canceller', 'creator', 'orgUnit']);

        $instance = $overtime->workflowInstances()
            ->with(['stageInstances.stage', 'stageInstances.approvals.user'])
            ->latest('id')
            ->first();

        return view('overtime.show', [
            'overtime' => $overtime,
            'instance' => $instance,
            'myApproval' => $instance?->stageInstances
                ->where('status', WorkflowStageInstance::STATUS_PENDING)
                ->first(fn (WorkflowStageInstance $stage) => $user->can('act', $stage)),
            'timeline' => $this->activity->timelineFor($overtime)->limit(25)->get(),
            'canEdit' => $user->can('update', $overtime),
            'canDelete' => $user->can('delete', $overtime),
            'canSubmit' => $user->can('submit', $overtime),
            'canCancel' => $user->can('cancel', $overtime),
            'canRecordWorked' => $user->can('recordWorked', $overtime),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', OvertimeRequest::class);
        $user = $request->user();

        return view('overtime.create', [
            'overtime' => new OvertimeRequest(['employee_id' => $user->employee?->id, 'work_date' => now()->toDateString()]),
            ...$this->formOptions($user),
        ]);
    }

    public function store(OvertimeRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', OvertimeRequest::class);
        $user = $request->user();
        $data = $this->reachable($request->validated(), $user);

        try {
            $overtime = $this->overtime->create($data, $user);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['hours' => $e->getMessage()]);
        }

        if ($request->boolean('submit_now') && $user->can('submit', $overtime)) {
            try {
                $this->overtime->submit($overtime, $user);
            } catch (\DomainException $e) {
                return redirect()->route('overtime.show', $overtime)
                    ->withErrors(['status_id' => $e->getMessage()])
                    ->with('status', $overtime->number.' has been saved as a draft, but could not be submitted.');
            }

            return redirect()->route('overtime.show', $overtime)->with('status', $overtime->number.' has been sent for approval.');
        }

        return redirect()->route('overtime.show', $overtime)->with('status', $overtime->number.' has been saved as a draft.');
    }

    public function edit(Request $request, OvertimeRequest $overtime): View
    {
        $this->authorize('update', $overtime);

        return view('overtime.edit', ['overtime' => $overtime, ...$this->formOptions($request->user(), $overtime)]);
    }

    public function update(OvertimeRequestRequest $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->authorize('update', $overtime);
        $data = $this->reachable($request->validated(), $request->user(), $overtime);

        try {
            $this->overtime->update($overtime, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['hours' => $e->getMessage()]);
        }

        return redirect()->route('overtime.show', $overtime)->with('status', $overtime->number.' has been updated.');
    }

    public function destroy(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->authorize('delete', $overtime);
        $this->recordDeletion($overtime, $request->user(), 'overtime request');
        $overtime->delete();

        return redirect()->route('overtime.index')->with('status', 'The draft overtime request has been deleted.');
    }

    public function submit(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->authorize('submit', $overtime);

        try {
            $this->overtime->submit($overtime, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The overtime request has been sent for approval.');
    }

    public function cancel(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->authorize('cancel', $overtime);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->overtime->cancel($overtime, $request->user(), $reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The overtime request has been cancelled.');
    }

    public function recordWorked(Request $request, OvertimeRequest $overtime): RedirectResponse
    {
        $this->authorize('recordWorked', $overtime);
        $hours = $request->validate(['worked_hours' => ['required', 'string', 'max:6']])['worked_hours'];

        try {
            $this->overtime->recordWorked($overtime, $hours, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['worked_hours' => $e->getMessage()]);
        }

        return back()->with('status', 'The hours worked have been recorded.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Whose overtime, and on which project. Somebody else's needs `overtime.manage` and sight of the
     * person; a project must be one that person works on or can see (§84).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function reachable(array $data, User $user, ?OvertimeRequest $existing = null): array
    {
        $own = $user->employee?->id;
        $chosen = $data['employee_id'] ?? null;

        if ($existing !== null) {
            unset($data['employee_id']);
        } elseif ($chosen === null || (int) $chosen === (int) $own) {
            if ($own === null) {
                abort(403);
            }
            $data['employee_id'] = $own;
        } elseif (! $user->hasPermission('overtime.manage')
            || ! $this->compiler->apply(Employee::query(), $user, 'employee.view')->whereKey($chosen)->exists()) {
            abort(403);
        }

        if (($projectId = $data['project_id'] ?? null) !== null) {
            $employee = Employee::query()->find($existing?->employee_id ?? $data['employee_id']);

            if ($employee === null || ! in_array((int) $projectId, $this->timesheets->allowedProjectIds($employee, $user), true)) {
                abort(403);
            }
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function formOptions(User $user, ?OvertimeRequest $existing = null): array
    {
        $canChoose = $existing === null && $user->hasPermission('overtime.manage');
        $employee = $existing?->employee ?? $user->employee;

        return [
            'projects' => $employee ? $this->timesheets->projectOptions($employee, $user) : [],
            'employees' => $canChoose
                ? $this->compiler->apply(Employee::query()->active(), $user, 'employee.view')
                    ->orderBy('first_name')->limit(500)->get()
                    ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])->all()
                : [],
            'canChooseEmployee' => $canChoose,
        ];
    }
}
