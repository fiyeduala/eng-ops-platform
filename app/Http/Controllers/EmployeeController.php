<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\EmployeeRequest;
use App\Models\Employee;
use App\Models\Location;
use App\Models\OrgUnit;
use App\Models\Position;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Personnel records (§4).
 *
 * Every list on this page is built by ScopeCompiler, which is the difference between a directory
 * and a leak: a team lead granted employee.view at `team` scope gets their team, a department head
 * gets their department, HR gets the company — from the same code path, with no per-role branching
 * anywhere in this controller.
 *
 * The three-way sensitivity split of EmployeePolicy (org-chart / contact / confidential) is
 * applied in BOTH directions here. The show and edit pages render only the blocks the viewer may
 * see, and EmployeeRequest::attributesFor() drops the rest of the payload on the way back in, so a
 * hand-edited form cannot write what its author could not read.
 */
class EmployeeController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Employee::class);

        $user = $request->user();

        $query = $this->compiler->apply(Employee::query(), $user, 'employee.view')
            ->with(['position:id,title', 'orgUnit:id,name', 'location:id,name']);

        // The rule lives on the model, so this page and the global search box ask one question
        // rather than two that happen to agree (§61).
        $query->search((string) $request->query('q', ''));

        // Filtering by a unit means "this unit and everything under it" — asking for Engineering
        // and getting only the people filed directly against the division, not the departments
        // inside it, is the sort of half-answer that makes people stop trusting a directory.
        if ($unitId = (int) $request->query('org_unit', 0)) {
            $unit = OrgUnit::find($unitId);

            if ($unit !== null && $user->can('view', $unit)) {
                // Called on the instance, not statically: scopeSubtree() reads $this->path, which
                // only exists on a hydrated unit.
                $query->whereIn('org_unit_id', $unit->subtree()->select('id'));
            }
        }

        if ($positionId = (int) $request->query('position', 0)) {
            $query->where('position_id', $positionId);
        }

        if ($supervisorId = (int) $request->query('supervisor', 0)) {
            $query->where('supervisor_id', $supervisorId);
        }

        $status = (string) $request->query('status', 'active');

        if ($status === 'active') {
            $query->active();
        } elseif (in_array($status, EmployeeRequest::employmentStatuses(), true)) {
            $query->where('employment_status', $status);
        }

        /** @var LengthAwarePaginator $employees */
        $employees = $query->orderBy('last_name')->orderBy('first_name')
            ->paginate(25)
            ->withQueryString();

        return view('employees.index', [
            'employees' => $employees,
            'units' => $this->unitOptions($request),
            'positions' => $this->positionOptions($request),
            'statuses' => $this->statusOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'org_unit' => $request->query('org_unit'),
                'position' => $request->query('position'),
                'supervisor' => $request->query('supervisor'),
                'status' => $status,
            ],
            'canCreate' => $user->can('create', Employee::class),
        ]);
    }

    public function show(Request $request, Employee $employee): View
    {
        $this->authorize('view', $employee);

        $user = $request->user();

        $employee->loadMissing(['orgUnit', 'position', 'supervisor', 'location', 'user']);

        // Direct reports are re-filtered rather than loaded through the relation: seeing someone
        // does not imply seeing everyone who reports to them.
        $reports = $this->compiler
            ->apply(Employee::query()->active()->where('supervisor_id', $employee->id), $user, 'employee.view')
            ->orderBy('last_name')->get();

        return view('employees.show', [
            'employee' => $employee,
            'reports' => $reports,
            'chain' => $this->visibleChain($request, $employee),
            'canSeeContact' => $user->can('viewContact', $employee),
            'canSeeConfidential' => $user->can('viewConfidential', $employee),
            'canEdit' => $user->can('update', $employee),
            'canDelete' => $user->can('delete', $employee),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Employee::class);

        return view('employees.create', [
            'employee' => new Employee([
                'employment_type' => 'full_time',
                'employment_status' => 'active',
                'is_active' => true,
            ]),
            ...$this->formOptions($request, null),
        ]);
    }

    public function store(EmployeeRequest $request): RedirectResponse
    {
        $this->authorize('create', Employee::class);

        $employee = new Employee;
        $employee->organization_id = $request->user()->organization_id;   // never from input (§74)

        $data = $request->attributesFor($employee);

        $this->assertPlacementIsReachable($request, $data);

        $employee->fill($data)->save();

        $this->activity->record($employee, 'created', $request->user()->name.' added '.$employee->fullName());

        return redirect()->route('employees.show', $employee)
            ->with('status', $employee->fullName().' has been added.');
    }

    public function edit(Request $request, Employee $employee): View
    {
        $this->authorize('update', $employee);

        return view('employees.edit', [
            'employee' => $employee,
            'canSeeContact' => $request->user()->can('viewContact', $employee),
            'canSeeConfidential' => $request->user()->can('viewConfidential', $employee),
            ...$this->formOptions($request, $employee),
        ]);
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->authorize('update', $employee);

        $data = $request->attributesFor($employee);

        $this->assertPlacementIsReachable($request, $data);

        $employee->fill($data)->save();

        $this->activity->record($employee, 'updated', $request->user()->name.' updated '.$employee->fullName());

        return redirect()->route('employees.show', $employee)
            ->with('status', $employee->fullName().' has been updated.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('delete', $employee);

        $name = $employee->fullName();

        $this->activity->record($employee, 'deleted', $request->user()->name.' removed '.$name);

        // Soft delete. A personnel record is referenced by attendance, approvals and signatures
        // going back years; destroying the row would leave every one of them pointing at nothing.
        $employee->delete();

        return redirect()->route('employees.index')
            ->with('status', $name.' has been removed.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A unit or supervisor the actor cannot reach is not a valid destination for a person.
     *
     * The form only ever offers reachable options, but the form is not the security boundary: a
     * POST can name any id in the tenant, and filing someone into a department you cannot see is
     * a way of moving data out of your own reach — or of quietly attaching yourself to a
     * supervisor whose subordinates you would then inherit.
     *
     * @param  array<string,mixed>  $data
     */
    private function assertPlacementIsReachable(Request $request, array $data): void
    {
        $user = $request->user();

        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }

        if (($supervisorId = $data['supervisor_id'] ?? null) !== null) {
            $this->authorize('view', Employee::findOrFail($supervisorId));
        }

        if (($userId = $data['user_id'] ?? null) !== null) {
            // Linking a personnel record to a login account decides whose reach that account
            // inherits, so it needs authority over the account, not merely over the person.
            $this->authorize('update', User::findOrFail($userId));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Employee $employee): array
    {
        $user = $request->user();

        return [
            'units' => $this->unitOptions($request),
            'positions' => $this->positionOptions($request),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'supervisors' => $this->compiler
                ->apply(Employee::query()->active(), $user, 'employee.view')
                ->when($employee !== null, fn ($q) => $q->whereKeyNot($employee->id))
                ->orderBy('last_name')->orderBy('first_name')
                ->limit(500)->get()
                ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])->all(),
            'accounts' => $this->accountOptions($request, $employee),
            'employmentTypes' => collect(EmployeeRequest::employmentTypes())
                ->mapWithKeys(fn (string $t) => [$t => ucwords(str_replace('_', ' ', $t))])->all(),
            'employmentStatuses' => collect(EmployeeRequest::employmentStatuses())
                ->mapWithKeys(fn (string $s) => [$s => ucwords(str_replace('_', ' ', $s))])->all(),
        ];
    }

    /**
     * Login accounts that are not already attached to somebody else.
     *
     * @return array<int|string,string>
     */
    private function accountOptions(Request $request, ?Employee $employee): array
    {
        if (! $request->user()->hasPermission('user.view')) {
            return [];
        }

        $taken = Employee::query()
            ->whereNotNull('user_id')
            ->when($employee !== null, fn ($q) => $q->whereKeyNot($employee->id))
            ->pluck('user_id');

        return $this->compiler
            ->apply(User::query()->active(), $request->user(), 'user.view')
            ->whereNotIn('id', $taken)
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' ('.$u->email.')'])
            ->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function unitOptions(Request $request): array
    {
        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $request->user(), 'org_unit.view')
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u) => [
                $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
            ])->all();
    }

    /**
     * @return array<int|string,string>
     */
    private function positionOptions(Request $request): array
    {
        if (! $request->user()->hasPermission('position.view')) {
            return [];
        }

        return $this->compiler
            ->apply(Position::query()->where('is_active', true), $request->user(), 'position.view')
            ->orderBy('title')->pluck('title', 'id')->all();
    }

    /**
     * @return array<string,string>
     */
    private function statusOptions(): array
    {
        return [
            'active' => 'Currently employed',
            ...collect(EmployeeRequest::employmentStatuses())
                ->mapWithKeys(fn (string $s) => [$s => ucwords(str_replace('_', ' ', $s))])->all(),
            'all' => 'Everyone on record',
        ];
    }

    /**
     * The reporting line upward, trimmed to the people the viewer may actually see.
     *
     * @return array<int,Employee>
     */
    private function visibleChain(Request $request, Employee $employee): array
    {
        $chain = $employee->chainUpward();

        if ($chain === []) {
            return [];
        }

        $visible = $this->compiler
            ->apply(Employee::query()->whereIn('id', array_column($chain, 'id')), $request->user(), 'employee.view')
            ->pluck('id')
            ->flip();

        return array_values(array_filter($chain, fn (Employee $e) => $visible->has($e->id)));
    }
}
