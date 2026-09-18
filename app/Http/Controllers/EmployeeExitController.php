<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\EmployeeExitRequest;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\User;
use App\Services\EmployeeExitService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Somebody leaving: what they still hold, and the day the access stops.
 *
 * THE BLOCKERS ON THE SCREEN ARE NOT WHAT IS CHECKED. The list rendered here is read fresh from the
 * asset and petty cash registers, and the service reads it AGAIN inside `clear()` and `complete()` —
 * a page somebody left open for an hour is not evidence that the laptop came back (§84).
 *
 * COMPLETING IS THE SENSITIVE ONE, and it is its own permission for that reason: it ends the
 * employment and switches the sign-in off in one transaction. An exit record that says somebody left
 * while their account still works is the failure this screen exists to prevent.
 *
 * THE EXIT INTERVIEW IS BEHIND ITS OWN PERMISSION. What somebody said on their way out is read by
 * whoever holds `employee_exit.view_confidential`, not by everybody who can see that they left (§68).
 */
class EmployeeExitController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly EmployeeExitService $exits,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'employee_exit';
    }

    protected function modelClass(): string
    {
        return EmployeeExit::class;
    }

    protected function routePrefix(): string
    {
        return 'employee-exits';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', EmployeeExit::class);
        $user = $request->user();
        $query = $this->scopedQuery($user, 'employee_exit.view')->with(['status', 'employee', 'clearedBy']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'open');

        if ($filters['view'] === 'open') {
            $query->whereHas('status', fn ($q) => $q->whereIn('key', ['notified', 'clearing']));
        }

        return view('employee-exits.index', [
            'exits' => $query->orderBy('last_working_day')->orderByDesc('id')->paginate(30)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('employee_exit'),
            'filters' => $filters,
            'canCreate' => $user->can('create', EmployeeExit::class),
        ]);
    }

    public function show(Request $request, EmployeeExit $employeeExit): View
    {
        $this->authorize('view', $employeeExit);
        $user = $request->user();
        $employeeExit->load(['status', 'employee.user', 'employee.orgUnit', 'checklist.items', 'clearedBy', 'creator']);

        return view('employee-exits.show', [
            'exit' => $employeeExit,
            'blockers' => $this->exits->blockers($employeeExit->employee),
            'timeline' => $this->activity->timelineFor($employeeExit)->limit(25)->get(),
            'canEdit' => $user->can('update', $employeeExit),
            'canDelete' => $user->can('delete', $employeeExit),
            'canClear' => $user->can('clear', $employeeExit),
            'canComplete' => $user->can('complete', $employeeExit),
            'canCancel' => $user->can('cancel', $employeeExit),
            'canViewInterview' => $user->can('viewInterview', $employeeExit),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', EmployeeExit::class);
        $user = $request->user();

        $employee = ($employeeId = (int) $request->query('employee', 0))
            ? $this->compiler->apply(Employee::query(), $user, 'employee.view')->find($employeeId)
            : null;

        return view('employee-exits.create', [
            'exit' => new EmployeeExit([
                'employee_id' => $employee?->id,
                'notified_on' => now()->toDateString(),
            ]),
            'blockers' => $employee !== null ? $this->exits->blockers($employee) : [],
            ...$this->formOptions($user),
        ]);
    }

    public function store(EmployeeExitRequest $request): RedirectResponse
    {
        $this->authorize('create', EmployeeExit::class);
        $data = $request->validated();
        $user = $request->user();

        // Being allowed to open exits is not being allowed to open one for anybody in the company.
        // The employee must be inside this actor's own scope (§84, §94).
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $this->authorize('view', $employee);
        $data = $this->withoutInterview($data, $user);

        try {
            $exit = $this->exits->open($employee, $data, $user);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['employee_id' => $e->getMessage()]);
        }

        return redirect()->route('employee-exits.show', $exit)
            ->with('status', $exit->number.' has been opened for '.$employee->fullName().'.');
    }

    public function edit(Request $request, EmployeeExit $employeeExit): View
    {
        $this->authorize('update', $employeeExit);

        return view('employee-exits.edit', [
            'exit' => $employeeExit,
            'blockers' => $this->exits->blockers($employeeExit->employee),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(EmployeeExitRequest $request, EmployeeExit $employeeExit): RedirectResponse
    {
        $this->authorize('update', $employeeExit);

        // Whose exit it is cannot be changed by re-posting the form: moving an exit onto another
        // person would end the wrong employment and close the wrong account.
        $data = $this->withoutInterview(
            collect($request->validated())->except('employee_id')->all(),
            $request->user(),
        );

        try {
            $this->exits->update($employeeExit, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('employee-exits.show', $employeeExit)
            ->with('status', $employeeExit->number.' has been updated.');
    }

    public function destroy(Request $request, EmployeeExit $employeeExit): RedirectResponse
    {
        $this->authorize('delete', $employeeExit);
        $this->recordDeletion($employeeExit, $request->user(), 'exit');
        $employeeExit->delete();

        return redirect()->route('employee-exits.index')->with('status', 'The exit has been deleted.');
    }

    // ------------------------------------------------------------------ the three decisions

    public function clear(Request $request, EmployeeExit $employeeExit): RedirectResponse
    {
        $this->authorize('clear', $employeeExit);

        try {
            $this->exits->clear($employeeExit, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['clearance' => $e->getMessage()]);
        }

        return back()->with('status', 'The clearance has been recorded.');
    }

    public function complete(Request $request, EmployeeExit $employeeExit): RedirectResponse
    {
        $this->authorize('complete', $employeeExit);

        try {
            $this->exits->complete($employeeExit, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['clearance' => $e->getMessage()]);
        }

        return back()->with('status', 'The exit is complete. Their access has been switched off.');
    }

    public function cancel(Request $request, EmployeeExit $employeeExit): RedirectResponse
    {
        $this->authorize('cancel', $employeeExit);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->exits->cancel($employeeExit, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The exit has been cancelled.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Drop the exit interview unless this person is allowed to read one.
     *
     * The field is hidden on the form for the same people, but hiding it is decoration — a POST
     * carrying `interview_notes` from somebody without `employee_exit.view_confidential` is dropped
     * here, on the server, where it counts (§6, §84).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function withoutInterview(array $data, User $user): array
    {
        if (! $user->hasPermission('employee_exit.view_confidential')) {
            unset($data['interview_notes']);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            'employees' => $this->compiler
                ->apply(Employee::query()->active(), $user, 'employee.view')
                ->orderBy('last_name')->orderBy('first_name')->limit(500)->get()
                ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName().' — '.$e->employee_no])
                ->all(),
            'reasons' => $this->lists->for('employee_exit.reasons', EmployeeExitService::DEFAULT_REASONS),
            // Asked of the permission rather than of the record, because the create form has no
            // record yet. The posted field is stripped on the same test below, so hiding the box is
            // never the thing doing the work (§6).
            'canViewInterview' => $user->hasPermission('employee_exit.view_confidential'),
        ];
    }
}
