<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\VisitorCheckInRequest;
use App\Http\Requests\VisitorRequest;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Models\Visitor;
use App\Services\VisitorService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Reception and the gate.
 *
 * NO SUBMIT, NO APPROVE, AND THAT IS THE POINT. Every other register in Phase 6 has a `submit` action
 * and no `approve` — they are routed, and the engine decides them. This one has neither: a
 * receptionist with somebody standing at the desk cannot wait on an inbox. The control is which of
 * `check_in` and `check_out` the acting user holds, and those are separate permissions from
 * `visitor.create` for a reason — booking a visitor for next Tuesday is an engineer telling reception
 * somebody is coming, and pressing the gate button is not.
 *
 * THE ROLL CALL IS BEHIND `visitor.view`, NOT `visitor.manage`. It is the screen somebody opens at an
 * assembly point with an alarm going, and an administrative permission standing between them and the
 * list of who is still inside would be a security control that gets people killed. It is still
 * scoped — a site administrator sees their own site's roll call, and nobody sees another tenant's
 * (§75).
 *
 * EVERY ACTION RE-AUTHORIZES THE RECORD. The route model binding hands over whatever id was in the
 * URL; `authorize()` on the record is what decides whether this user may touch this visit (§84, §94).
 */
class VisitorController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly VisitorService $visitors,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'visitor';
    }

    protected function modelClass(): string
    {
        return Visitor::class;
    }

    protected function routePrefix(): string
    {
        return 'visitors';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Visitor::class);
        $user = $request->user();

        $query = $this->scopedQuery($user, 'visitor.view')
            ->with(['status', 'host:id,first_name,last_name', 'location:id,name']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'open');

        // "Open" on this register means the visit has not finished — booked and not yet arrived, or
        // arrived and not yet gone. It is the working list at a front desk.
        if ($filters['view'] === 'open') {
            $query->whereHas('status', fn ($q) => $q->whereIn('key', ['expected', 'on_site']));
        }

        if (($purpose = trim((string) $request->query('purpose', ''))) !== '') {
            $query->where('purpose_type', $purpose);
        }

        return view('visitors.index', [
            'visitors' => $query->orderByRaw('checked_in_at is null')
                ->orderByDesc('checked_in_at')
                ->orderBy('expected_from')
                ->orderByDesc('id')
                ->paginate(30)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('visitor'),
            'purposes' => $this->purposeOptions($user),
            'filters' => [...$filters, 'purpose' => $purpose],
            'canCreate' => $user->can('create', Visitor::class),
            'canRollCall' => $user->can('rollCall', Visitor::class),
        ]);
    }

    /**
     * Who is in the building.
     *
     * Deliberately its own screen and not a filter on the register. Somebody reaching for this is not
     * browsing — and a page that answers one question in one list, with a headcount at the top, is
     * the difference between reading it and hunting through it.
     */
    public function rollCall(Request $request): View
    {
        $this->authorize('rollCall', Visitor::class);
        $user = $request->user();

        $locationId = (int) $request->query('location', 0) ?: null;

        $query = $this->scopedQuery($user, 'visitor.view')
            ->onSite()
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->with([
                'host:id,first_name,last_name',
                'escort:id,first_name,last_name',
                'location:id,name',
            ])
            ->orderBy('checked_in_at');

        $onSite = $query->get();

        return view('visitors.roll-call', [
            'onSite' => $onSite,
            // Heads, not rows. A delegation of six arrived on one record, and an assembly point
            // counting rows would be six people short without knowing it.
            'headcount' => $onSite->sum(fn (Visitor $v) => $v->headcount()),
            'locations' => Location::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'locationId' => $locationId,
            'canCheckOut' => $user->hasPermission('visitor.check_out'),
        ]);
    }

    public function show(Request $request, Visitor $visitor): View
    {
        $this->authorize('view', $visitor);
        $user = $request->user();

        $visitor->load([
            'status', 'host.position', 'hostUser', 'escort', 'orgUnit', 'location', 'project',
            'checkedInBy', 'checkedOutBy',
        ]);

        return view('visitors.show', [
            'visitor' => $visitor,
            'timeline' => $this->activity->timelineFor($visitor)->limit(25)->get(),
            'canEdit' => $user->can('update', $visitor),
            'canDelete' => $user->can('delete', $visitor),
            'canCheckIn' => $user->can('checkIn', $visitor),
            'canCheckOut' => $user->can('checkOut', $visitor),
            'canCancel' => $user->can('cancel', $visitor),
            'canRefuse' => $user->can('refuse', $visitor),
            'escorts' => $user->can('checkIn', $visitor) ? $this->escortOptions($user) : [],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Visitor::class);

        return view('visitors.create', [
            'visitor' => new Visitor([
                'party_size' => 1,
                'expected_from' => now()->addHour()->startOfHour()->format('Y-m-d\TH:i'),
            ]),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(VisitorRequest $request): RedirectResponse
    {
        $this->authorize('create', Visitor::class);
        $data = $request->validated();
        $user = $request->user();

        // Being allowed to book visitors is not being allowed to book one against anybody in the
        // company. The host must be somebody this user can actually see (§84, §94).
        if (($hostId = $data['host_employee_id'] ?? null) !== null) {
            $this->authorize('view', Employee::query()->findOrFail($hostId));
        }

        try {
            $visitor = $this->visitors->create($data, $user);
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['full_name' => $e->getMessage()]);
        }

        return redirect()->route('visitors.show', $visitor)
            ->with('status', $visitor->number.' has been booked.');
    }

    public function edit(Request $request, Visitor $visitor): View
    {
        $this->authorize('update', $visitor);

        return view('visitors.edit', [
            'visitor' => $visitor,
            ...$this->formOptions($request->user()),
        ]);
    }

    public function update(VisitorRequest $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('update', $visitor);
        $data = $request->validated();

        if (($hostId = $data['host_employee_id'] ?? null) !== null) {
            $this->authorize('view', Employee::query()->findOrFail($hostId));
        }

        try {
            $this->visitors->update($visitor, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['full_name' => $e->getMessage()]);
        }

        return redirect()->route('visitors.show', $visitor)
            ->with('status', $visitor->number.' has been updated.');
    }

    public function destroy(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('delete', $visitor);
        $this->recordDeletion($visitor, $request->user(), 'visit');
        $visitor->delete();

        return redirect()->route('visitors.index')->with('status', 'The booking has been deleted.');
    }

    // ------------------------------------------------------------------ the gate

    public function checkIn(VisitorCheckInRequest $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('checkIn', $visitor);

        try {
            $this->visitors->checkIn($visitor, $request->user(), $request->validated());
        } catch (\DomainException $e) {
            return back()->withErrors(['visitor' => $e->getMessage()]);
        }

        return back()->with('status', $visitor->full_name.' is signed in.');
    }

    public function checkOut(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('checkOut', $visitor);

        try {
            $this->visitors->checkOut($visitor, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['visitor' => $e->getMessage()]);
        }

        return back()->with('status', $visitor->full_name.' is signed out.');
    }

    public function refuse(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('refuse', $visitor);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        try {
            $this->visitors->refuse($visitor, $request->user(), $reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Entry has been refused, and the reason is on the record.');
    }

    public function cancel(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('cancel', $visitor);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;

        try {
            $this->visitors->cancel($visitor, $request->user(), $reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The visit has been cancelled.');
    }

    public function noShow(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('cancel', $visitor);

        try {
            $this->visitors->markNoShow($visitor, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['visitor' => $e->getMessage()]);
        }

        return back()->with('status', 'Recorded as not arrived.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            'hosts' => $this->escortOptions($user),
            'units' => $this->unitOptions($user),
            'projects' => $this->projectOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'purposes' => $this->purposeOptions($user),
        ];
    }

    /**
     * Hosts and escorts are the same list: employees this user can see.
     *
     * Employees rather than accounts, for the reason movements use employees for supervisors — a
     * delivery is received by a storeman who may never have signed in, and a visitor book that could
     * not name him would be missing the host on exactly the visits that most need one.
     *
     * @return array<int,string>
     */
    private function escortOptions(User $user): array
    {
        return $this->compiler
            ->apply(Employee::query()->active(), $user, 'employee.view')
            ->orderBy('last_name')->orderBy('first_name')->limit(500)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])
            ->all();
    }

    /**
     * The reasons a company records for a visit — its own words, not ours (§47).
     *
     * The stored value is the slug, so `contractor` is what the escort rule looks for regardless of
     * whether a company labels it "Contractor" or "Contractor / subcontractor".
     *
     * @return array<string,string>
     */
    private function purposeOptions(User $user): array
    {
        return $this->lists->for('visitor.purpose_types', Visitor::DEFAULT_PURPOSE_TYPES, $user->organization_id);
    }
}
