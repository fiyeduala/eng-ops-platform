<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\EmployeeMovementRequest;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\Location;
use App\Models\Position;
use App\Models\User;
use App\Services\EmployeeMovementService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Promotions and transfers: raised, routed, and applied on the day they take effect.
 *
 * THE APPLY BUTTON IS NOT THE APPROVAL. Approval happens on the workflow route, like everything else
 * in Phase 6 — there is no approve action on this controller. `apply` is what writes the change onto
 * the personnel record afterwards, it has its own permission, and it refuses to run before the
 * effective date. Most of the time nobody presses it at all: the nightly sweep applies movements on
 * the morning they are due.
 *
 * THE EMPLOYEE IS RE-AUTHORIZED ON STORE. Being allowed to raise movements is not being allowed to
 * raise one for anybody in the company — the person must be inside the actor's own reach (§84, §94).
 */
class EmployeeMovementController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly EmployeeMovementService $movements,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'employee_movement';
    }

    protected function modelClass(): string
    {
        return EmployeeMovement::class;
    }

    protected function routePrefix(): string
    {
        return 'employee-movements';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', EmployeeMovement::class);
        $user = $request->user();

        $query = $this->scopedQuery($user, 'employee_movement.view')
            ->with(['status', 'employee', 'toPosition', 'toOrgUnit']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'open');

        if ($filters['view'] === 'open') {
            $query->whereHas('status', fn ($q) => $q->whereIn('key', ['draft', 'submitted', 'approved']));
        }

        if (($kind = trim((string) $request->query('kind', ''))) !== '') {
            $query->where('kind', $kind);
        }

        return view('employee-movements.index', [
            'movements' => $query->orderByDesc('effective_on')->orderByDesc('id')->paginate(30)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('employee_movement'),
            'kinds' => $this->kindOptions($user),
            'filters' => [...$filters, 'kind' => $kind],
            'canCreate' => $user->can('create', EmployeeMovement::class),
        ]);
    }

    public function show(Request $request, EmployeeMovement $employeeMovement): View
    {
        $this->authorize('view', $employeeMovement);
        $user = $request->user();
        $employeeMovement->load([
            'status', 'employee.position', 'requester', 'appliedBy',
            'fromPosition', 'fromOrgUnit', 'fromLocation', 'fromSupervisor',
            'toPosition', 'toOrgUnit', 'toLocation', 'toSupervisor',
        ]);

        $instance = $employeeMovement->workflowInstances()
            ->with(['stageInstances.stage', 'stageInstances.approvals.user'])
            ->latest('id')
            ->first();

        return view('employee-movements.show', [
            'movement' => $employeeMovement,
            'instance' => $instance,
            'timeline' => $this->activity->timelineFor($employeeMovement)->limit(25)->get(),
            'canEdit' => $user->can('update', $employeeMovement),
            'canDelete' => $user->can('delete', $employeeMovement),
            'canSubmit' => $user->can('submit', $employeeMovement),
            'canApply' => $user->can('apply', $employeeMovement),
            'canCancel' => $user->can('cancel', $employeeMovement),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', EmployeeMovement::class);
        $user = $request->user();

        $employee = ($employeeId = (int) $request->query('employee', 0))
            ? $this->compiler->apply(Employee::query(), $user, 'employee.view')->find($employeeId)
            : null;

        return view('employee-movements.create', [
            'movement' => new EmployeeMovement([
                'employee_id' => $employee?->id,
                'kind' => 'promotion',
                'effective_on' => now()->addMonth()->startOfMonth()->toDateString(),
            ]),
            ...$this->formOptions($user),
        ]);
    }

    public function store(EmployeeMovementRequest $request): RedirectResponse
    {
        $this->authorize('create', EmployeeMovement::class);
        $data = $request->validated();
        $user = $request->user();

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $this->authorize('view', $employee);

        try {
            $movement = $this->movements->create($data, $user);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['employee_id' => $e->getMessage()]);
        }

        return redirect()->route('employee-movements.show', $movement)
            ->with('status', $movement->number.' has been drafted.');
    }

    public function edit(Request $request, EmployeeMovement $employeeMovement): View
    {
        $this->authorize('update', $employeeMovement);

        return view('employee-movements.edit', [
            'movement' => $employeeMovement,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(EmployeeMovementRequest $request, EmployeeMovement $employeeMovement): RedirectResponse
    {
        $this->authorize('update', $employeeMovement);

        try {
            $this->movements->update($employeeMovement, $request->validated(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['kind' => $e->getMessage()]);
        }

        return redirect()->route('employee-movements.show', $employeeMovement)
            ->with('status', $employeeMovement->number.' has been updated.');
    }

    public function destroy(Request $request, EmployeeMovement $employeeMovement): RedirectResponse
    {
        $this->authorize('delete', $employeeMovement);
        $this->recordDeletion($employeeMovement, $request->user(), 'movement');
        $employeeMovement->delete();

        return redirect()->route('employee-movements.index')->with('status', 'The movement has been deleted.');
    }

    // ------------------------------------------------------------------ the three acts

    public function submit(Request $request, EmployeeMovement $employeeMovement): RedirectResponse
    {
        $this->authorize('submit', $employeeMovement);

        try {
            $this->movements->submit($employeeMovement, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['movement' => $e->getMessage()]);
        }

        return back()->with('status', $employeeMovement->number.' has been sent for approval.');
    }

    public function apply(Request $request, EmployeeMovement $employeeMovement): RedirectResponse
    {
        $this->authorize('apply', $employeeMovement);

        try {
            $this->movements->apply($employeeMovement, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['movement' => $e->getMessage()]);
        }

        return back()->with('status', 'The change has been applied to the personnel record.');
    }

    public function cancel(Request $request, EmployeeMovement $employeeMovement): RedirectResponse
    {
        $this->authorize('cancel', $employeeMovement);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->movements->cancel($employeeMovement, $reason, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The movement has been cancelled.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            'employees' => $this->compiler
                ->apply(Employee::query()->active(), $user, 'employee.view')
                ->orderBy('last_name')->orderBy('first_name')->limit(500)->get()
                ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName().' — '.$e->employee_no])
                ->all(),
            'positions' => $user->hasPermission('position.view')
                ? $this->compiler->apply(Position::query()->where('is_active', true), $user, 'position.view')
                    ->orderBy('title')->pluck('title', 'id')->all()
                : [],
            'units' => $this->unitOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            // Supervisors are employees, not accounts: a movement can put somebody under a manager
            // who has never signed in, and the reporting line is a fact about the company either way.
            'supervisors' => $this->compiler
                ->apply(Employee::query()->active(), $user, 'employee.view')
                ->orderBy('last_name')->orderBy('first_name')->limit(500)->get()
                ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])
                ->all(),
            'kinds' => $this->kindOptions($user),
        ];
    }

    /**
     * The kinds a company uses. Seeded from the defaults and editable as company data — a handbook
     * with "redeployment" in it is describing something real (§47).
     *
     * @return array<string,string>
     */
    private function kindOptions(User $user): array
    {
        $keys = $this->lists->keys('employee_movement.kinds', EmployeeMovement::DEFAULT_KINDS, $user->organization_id);

        return collect($keys)->mapWithKeys(fn (string $k) => [$k => ucfirst(str_replace('_', ' ', $k))])->all();
    }
}
