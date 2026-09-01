<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\PermitRequest;
use App\Models\Permit;
use App\Models\PermitPrecaution;
use App\Models\PermitType;
use App\Services\PermitService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Permits to work — the register where being wrong gets somebody killed.
 *
 * NOBODY ISSUES THEIR OWN PERMIT. Not the requester, not the person who typed it up. The policy
 * refuses it and PermitService refuses it again, because the policy guards HTTP and the service
 * guards every caller — a workflow auto-action and an import included. This is the rule the whole
 * module exists to enforce and it is not configurable at any scope.
 *
 * EVERY ACT IS ITS OWN ENDPOINT BEHIND ITS OWN PERMISSION, and not one of them is on the edit form.
 * Issue, reject, accept, suspend, resume, close, cancel, confirm-a-precaution and record-a-gas-test
 * are nine separate decisions made by up to five different people, each of which attaches a name
 * and a timestamp to a document that will be read out in an inquiry. A form that could set
 * `issued_at` is a form that can forge an authorisation (§84).
 *
 * SUSPENDING IS DELIBERATELY THE EASIEST THING IN HERE and resuming is deliberately not. Anybody
 * with edit reach can stop the work; only somebody who could have issued the permit can restart it.
 * Requiring the issuer's authority to stop work would mean work continuing while somebody goes
 * looking for them.
 *
 * THERE IS NO `submit` ENDPOINT, because raising the permit IS the request — a permit created in
 * `requested` has already been submitted, and a draft state would only produce permits sitting
 * unseen in somebody's queue while the work waits. PermitPolicy::submit remains for configurations
 * that add a draft stage of their own to the status graph.
 */
class PermitController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly PermitService $permits,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'permit';
    }

    protected function modelClass(): string
    {
        return Permit::class;
    }

    protected function routePrefix(): string
    {
        return 'permits';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Permit::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'permitType:id,name,code',
            'requester:id,name', 'issuer:id,name',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($typeId = (int) $request->query('type', 0)) {
            $query->where('permit_type_id', $typeId);
        }

        // `live` IS THE DEFAULT AND THE REASON THE SCREEN EXISTS. "What is authorised on site right
        // now" is the question a control room refreshes all day; every other view on this register
        // is secondary to it.
        $view = (string) $request->query('view', 'live');

        match ($view) {
            'awaiting' => $query->whereNull('issued_at')->whereNull('cancelled_at')->whereNull('closed_at'),
            'expired' => $query->expiredAndOpen(),
            'mine' => $query->where(fn ($q) => $q->where('requested_by', $user->id)
                ->orWhere('accepted_by', $user->id)
                ->orWhere('created_by', $user->id)),
            'all' => $query,
            default => $query->live(),
        };

        /** @var LengthAwarePaginator $permits */
        $permits = $query
            ->orderByRaw('CASE WHEN valid_to IS NULL THEN 1 ELSE 0 END')
            ->orderBy('valid_to')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('permits.index', [
            'permits' => $permits,
            'statuses' => $this->statuses->optionsFor('permit'),
            'projects' => $this->projectOptions($user),
            'permitTypes' => $this->permitTypeOptions(),
            'views' => [
                'live' => 'Live now',
                'awaiting' => 'Awaiting issue',
                'expired' => 'Expired, not closed',
                'mine' => 'Mine',
                'all' => 'Everything you can see',
            ],
            'filters' => [...$this->commonFilters($request, 'live'), 'type' => $request->query('type')],
            'canCreate' => $user->can('create', Permit::class),
        ]);
    }

    public function show(Request $request, Permit $permit): View
    {
        $this->authorize('view', $permit);

        $user = $request->user();

        $permit->loadMissing([
            'status', 'project', 'discipline', 'orgUnit', 'permitType',
            'requester:id,name', 'issuer:id,name', 'acceptor:id,name', 'closer:id,name',
            'gasTester:id,name',
            'creator:id,name',
            'precautions.confirmer:id,name',
        ]);

        return view('permits.show', [
            'permit' => $permit,
            'timeline' => $this->activity->timelineFor($permit)->limit(25)->get(),
            'transitions' => $this->statuses->available('permit', $permit->status_id, $user),
            'workflowInstance' => $permit->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $permit),
            'canDelete' => $user->can('delete', $permit),
            'canIssue' => $user->can('issue', $permit),
            'canReject' => $user->can('reject', $permit),
            'canAccept' => $user->can('accept', $permit),
            'canSuspend' => $user->can('suspend', $permit),
            'canResume' => $user->can('resume', $permit),
            'canClose' => $user->can('close', $permit),
            'canCancel' => $user->can('cancel', $permit),
            // The gas test is recorded by whoever holds the meter, which is rarely the issuer and
            // frequently not the requester. `update` reach is the right gate for it; the refusal
            // that matters — no issue without a result when the type demands one — is in the
            // service, where every caller meets it.
            'canRecordGasTest' => ! $permit->isIssued() && $user->can('update', $permit),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Permit::class);

        $user = $request->user();

        return view('permits.create', [
            'permit' => new Permit([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
                'requested_by' => $user->id,
                'valid_from' => now()->format('Y-m-d\TH:i'),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(PermitRequest $request): RedirectResponse
    {
        $this->authorize('create', Permit::class);

        $data = $request->permitAttributes();

        $this->assertPlacementIsReachable($data);

        $permit = $this->permits->create($data, $request->user());

        return redirect($this->showRoute($permit))
            ->with('status', $permit->displayName().' has been requested — the precautions are now yours to confirm.');
    }

    public function edit(Request $request, Permit $permit): View
    {
        $this->authorize('update', $permit);

        return view('permits.edit', [
            'permit' => $permit,
            ...$this->formOptions($request),
        ]);
    }

    public function update(PermitRequest $request, Permit $permit): RedirectResponse
    {
        $this->authorize('update', $permit);

        $data = $request->permitAttributes();

        $this->assertPlacementIsReachable($data);

        $this->permits->update($permit, $data, $request->user());

        return redirect($this->showRoute($permit))
            ->with('status', $permit->displayName().' has been updated.');
    }

    public function destroy(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('delete', $permit);

        $name = $permit->displayName();

        $this->recordDeletion($permit, $request->user(), 'permit');

        $permit->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ precautions

    /**
     * Tick one precaution, having actually checked the thing.
     *
     * ONE ENDPOINT PER LINE, NOT ONE FOR THE CHECKLIST. The question after an incident is never
     * "was the checklist completed" — it is "who confirmed that the space had been purged, and
     * when". A bulk-confirm button would answer that question with one name and one timestamp for
     * every line, which is the same as not answering it.
     */
    public function confirmPrecaution(Request $request, Permit $permit, PermitPrecaution $precaution): RedirectResponse
    {
        abort_unless((int) $precaution->permit_id === (int) $permit->getKey(), 404);

        $this->authorize('confirm', $precaution);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->permits->confirmPrecaution($precaution, $request->user(), $validated['comment'] ?? null);

        return back()->with('status', 'The precaution has been confirmed.');
    }

    public function recordGasTest(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('update', $permit);

        $validated = $request->validate([
            // Free text, and deliberately not a pass/fail flag. What matters on a confined-space
            // permit is the readings — O₂ 20.9%, LEL 0%, H₂S 0ppm — and a boolean throws away the
            // only part anybody would want to read back (§47: no assumed instrument, no assumed
            // units, no assumed threshold).
            'gas_test_result' => ['required', 'string', 'max:200'],
        ]);

        try {
            $this->permits->recordGasTest($permit, $request->user(), $validated['gas_test_result']);
        } catch (\DomainException $e) {
            return back()->withErrors(['gas_test_result' => $e->getMessage()]);
        }

        return back()->with('status', 'The gas test has been recorded on '.$permit->displayName().'.');
    }

    // ------------------------------------------------------------------ lifecycle

    public function issue(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('issue', $permit);

        try {
            $this->permits->issue($permit, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return back()->with('status', $permit->displayName().' has been issued.');
    }

    public function reject(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('reject', $permit);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->permits->reject($permit, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $permit->displayName().' has been refused.');
    }

    public function accept(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('accept', $permit);

        try {
            $this->permits->accept($permit, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['accept' => $e->getMessage()]);
        }

        return back()->with('status', 'You have accepted '.$permit->displayName().'.');
    }

    public function suspend(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('suspend', $permit);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->permits->suspend($permit, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'Work under '.$permit->displayName().' has been stopped.');
    }

    public function resume(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('resume', $permit);

        try {
            $this->permits->resume($permit, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['resume' => $e->getMessage()]);
        }

        return back()->with('status', $permit->displayName().' has been resumed.');
    }

    public function close(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('close', $permit);

        $validated = $request->validate([
            'closure_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $this->permits->close($permit, $request->user(), $validated['closure_notes'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['close' => $e->getMessage()]);
        }

        return back()->with('status', $permit->displayName().' has been closed and the area handed back.');
    }

    public function cancel(Request $request, Permit $permit): RedirectResponse
    {
        $this->authorize('cancel', $permit);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->permits->cancel($permit, $request->user(), $validated['reason']);
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $permit->displayName().' has been cancelled.');
    }

    public function transition(Request $request, Permit $permit): RedirectResponse
    {
        return $this->moveStatus($request, $permit);
    }

    // ------------------------------------------------------------------ options

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            ...$this->placementOptions($request->user()),
            'permitTypes' => $this->permitTypeOptions(),
            'nextNumber' => $this->numbers->preview('permit'),
        ];
    }

    /**
     * The catalogue, active entries only.
     *
     * No scope compiler: a permit type is tenant configuration rather than a record with reach, so
     * the organization scope on the model is the whole of the authorization. Inactive types are
     * excluded because deactivating one is how a company retires a permit it no longer issues —
     * without disturbing the permits already raised against it.
     *
     * @return array<int|string,string>
     */
    private function permitTypeOptions(): array
    {
        return PermitType::query()
            ->active()
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->mapWithKeys(fn (PermitType $t) => [$t->id => $t->displayName()])
            ->all();
    }
}
