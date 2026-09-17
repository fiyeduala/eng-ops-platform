<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\LeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Models\WorkflowStageInstance;
use App\Services\LeaveService;
use App\Support\OptionList;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Leave: booked by the person taking it, decided in My Approvals.
 *
 * There is no approve or reject action here, on purpose — see LeaveRequestPolicy. The show page
 * links to the approval, and the decision is made exactly where a document's is.
 */
class LeaveRequestController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly LeaveService $leave,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'leave_request';
    }

    protected function modelClass(): string
    {
        return LeaveRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'leave';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', LeaveRequest::class);
        $user = $request->user();
        $query = $this->scopedQuery($user, 'leave.view')->with(['status', 'employee', 'leaveType']);
        $this->applyCommonFilters($query, $request);

        $filters = $this->commonFilters($request, 'mine');

        if ($filters['view'] === 'mine') {
            $query->where('requester_id', $user->id);
        }

        if ($typeId = (int) $request->query('type', 0)) {
            $query->where('leave_type_id', $typeId);
        }

        return view('leave.index', [
            'requests' => $query->orderByDesc('start_date')->orderByDesc('id')->paginate(40)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('leave_request'),
            'filters' => $filters,
            'balances' => $this->balancesFor($user->employee, (int) now()->year),
            'canCreate' => $user->can('create', LeaveRequest::class),
        ]);
    }

    /**
     * Who is away this month, among the people this user may see leave for.
     *
     * Types are shown only as "away" to anybody who is not reading their own row: the calendar is
     * for planning cover, and sick leave is nobody's business but the requester's and HR's (§68).
     */
    public function calendar(Request $request): View
    {
        $this->authorize('viewAny', LeaveRequest::class);
        $user = $request->user();

        $month = CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month', now()->format('Y-m')))
            ?: CarbonImmutable::now()->startOfMonth();
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $requests = $this->scopedQuery($user, 'leave.view')
            ->with(['status', 'employee', 'leaveType'])
            ->whereIn('status_id', $this->leave->committedStatusIds())
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->limit(500)
            ->get();

        $holidays = PublicHoliday::query()
            ->where(fn ($q) => $q->where('is_recurring', true)->orWhereBetween('holiday_date', [$start->toDateString(), $end->toDateString()]))
            ->get();

        $days = [];
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = [
                'date' => $day,
                'holiday' => $holidays->first(fn (PublicHoliday $h) => $h->fallsOn($day))?->name,
            ];
        }

        return view('leave.calendar', [
            'month' => $start,
            'days' => $days,
            'rows' => $requests->groupBy('employee_id'),
            'showTypes' => $user->hasPermission('leave.manage'),
        ]);
    }

    public function show(Request $request, LeaveRequest $leave): View
    {
        $this->authorize('view', $leave);
        $user = $request->user();
        $leave->load(['status', 'employee', 'leaveType', 'requester', 'handoverTo', 'canceller', 'creator', 'orgUnit']);

        $instance = $leave->workflowInstances()
            ->with(['stageInstances.stage', 'stageInstances.approvals.user'])
            ->latest('id')
            ->first();

        // The stage this user can act on, if any — a link to the one place decisions are made.
        $myApproval = $instance?->stageInstances
            ->where('status', WorkflowStageInstance::STATUS_PENDING)
            ->first(fn (WorkflowStageInstance $stage) => $user->can('act', $stage));

        return view('leave.show', [
            'leave' => $leave,
            'instance' => $instance,
            'myApproval' => $myApproval,
            'timeline' => $this->activity->timelineFor($leave)->limit(25)->get(),
            'canEdit' => $user->can('update', $leave),
            'canDelete' => $user->can('delete', $leave),
            'canSubmit' => $user->can('submit', $leave),
            'canCancel' => $user->can('cancel', $leave),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', LeaveRequest::class);
        $user = $request->user();

        return view('leave.create', [
            'leave' => new LeaveRequest([
                'employee_id' => $user->employee?->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDay()->toDateString(),
            ]),
            ...$this->formOptions($user),
        ]);
    }

    public function store(LeaveRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', LeaveRequest::class);
        $data = $this->reachable($request->validated(), $request->user());

        try {
            $leave = $this->leave->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['start_date' => $e->getMessage()]);
        }

        if ($request->boolean('submit_now') && $request->user()->can('submit', $leave)) {
            try {
                $this->leave->submit($leave, $request->user());
            } catch (\DomainException $e) {
                return redirect()->route('leave.show', $leave)
                    ->withErrors(['status_id' => $e->getMessage()])
                    ->with('status', $leave->number.' has been saved as a draft, but could not be submitted.');
            }

            return redirect()->route('leave.show', $leave)->with('status', $leave->number.' has been submitted.');
        }

        return redirect()->route('leave.show', $leave)->with('status', $leave->number.' has been saved as a draft.');
    }

    public function edit(Request $request, LeaveRequest $leave): View
    {
        $this->authorize('update', $leave);

        return view('leave.edit', ['leave' => $leave, ...$this->formOptions($request->user())]);
    }

    public function update(LeaveRequestRequest $request, LeaveRequest $leave): RedirectResponse
    {
        $this->authorize('update', $leave);
        $data = $this->reachable($request->validated(), $request->user(), $leave);

        try {
            $this->leave->update($leave, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['start_date' => $e->getMessage()]);
        }

        return redirect()->route('leave.show', $leave)->with('status', $leave->number.' has been updated.');
    }

    public function destroy(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $this->authorize('delete', $leave);
        $this->recordDeletion($leave, $request->user(), 'leave request');
        $leave->delete();

        return redirect()->route('leave.index')->with('status', 'The draft leave request has been deleted.');
    }

    public function submit(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $this->authorize('submit', $leave);

        try {
            $this->leave->submit($leave, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $leave->statusKey() === 'approved'
            ? 'The leave has been booked.'
            : 'The leave request has been sent for approval.');
    }

    public function cancel(Request $request, LeaveRequest $leave): RedirectResponse
    {
        $this->authorize('cancel', $leave);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
        $user = $request->user();

        try {
            $this->leave->cancel($leave, $user, $reason, asManager: $user->can('manage', $leave));
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The leave request has been cancelled.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Whose leave this is.
     *
     * Booking for somebody else needs `leave.manage` and sight of that person. Without it the
     * request is always for the person filling in the form — whatever the form says (§84).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function reachable(array $data, User $user, ?LeaveRequest $existing = null): array
    {
        $own = $existing?->employee_id ?? $user->employee?->id;
        $chosen = $data['employee_id'] ?? null;

        if ($existing !== null) {
            unset($data['employee_id']);
        } elseif ($chosen === null || (int) $chosen === (int) $own) {
            $data['employee_id'] = $own;
        } elseif (! $user->hasPermission('leave.manage')
            || ! $this->compiler->apply(Employee::query(), $user, 'employee.view')->whereKey($chosen)->exists()) {
            abort(403);
        }

        if (($handover = $data['handover_to'] ?? null) !== null
            && ! $this->compiler->apply(User::query()->active(), $user, 'employee.view')->whereKey($handover)->exists()) {
            abort(403);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        $canChoose = $user->hasPermission('leave.manage');

        return [
            'types' => LeaveType::query()->active()->orderBy('sort')->orderBy('name')->get()
                ->mapWithKeys(fn (LeaveType $type) => [$type->id => $type->name])->all(),
            'colleagues' => array_diff_key($this->userOptions($user), [$user->id => true]),
            'employees' => $canChoose
                ? $this->compiler->apply(Employee::query()->active(), $user, 'employee.view')
                    ->orderBy('first_name')->limit(500)->get()
                    ->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()])->all()
                : [],
            'canChooseEmployee' => $canChoose,
            'balances' => $this->balancesFor($user->employee, (int) now()->year),
        ];
    }

    /**
     * What this person has left of each counted type — read, never written.
     *
     * @return array<int,array{type:LeaveType,granted:string,used:string,left:string}>
     */
    private function balancesFor(?Employee $employee, int $year): array
    {
        if ($employee === null) {
            return [];
        }

        $rows = [];

        foreach (LeaveType::query()->active()->whereNotNull('days_per_year')->orderBy('sort')->get() as $type) {
            $balance = $this->leave->balanceFor($employee, $type, $year, persist: false);
            $used = $this->leave->daysUsed($employee, $type, $year);

            $rows[] = [
                'type' => $type,
                'granted' => $balance->granted(),
                'used' => $used,
                'left' => (string) BigDecimal::of($balance->granted())->minus($used)->toScale(2),
            ];
        }

        return $rows;
    }
}
