<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ProcurementDecisionRequest;
use App\Http\Requests\PurchaseRequestRequest;
use App\Models\Location;
use App\Models\PurchaseRequest;
use App\Models\Rfq;
use App\Services\PurchaseRequestService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Somebody needs something. This is where they say so.
 *
 * THE FIRST LINK IN THE CHAIN (§90). Nothing else in procurement starts on its own: an enquiry is
 * raised from a request, an order is raised from a quotation, a delivery is booked against an
 * order. The register exists so that the question "why did we buy this" has an answer written by
 * the person who wanted it, before any money was discussed.
 *
 * IT DOES NOT ACCEPT A TOTAL. `estimated_total` is a stamped sum of the lines, computed by the
 * service, and the form request has no rule for it. A posted total would be the cheapest possible
 * way to slip a large purchase past a threshold approval (§84).
 *
 * APPROVAL IS NOT SELF-SERVICE. PurchaseRequestService applies the segregation rule — the person
 * who raised it is not the person who approves it — as a setting rather than a policy, so that a
 * six-person consultancy can switch it off and have the waiver recorded rather than being locked
 * out of its own procurement. The controller does not repeat that rule; it reports the refusal.
 *
 * WHAT `ordered` MEANS HERE. It is set by PurchaseOrderService when an order is issued against the
 * request, never clicked, because it is a fact about another record rather than a decision anybody
 * takes. That is why the status graph gives it no transition.
 */
class PurchaseRequestController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly PurchaseRequestService $requests,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'purchase_request';
    }

    protected function modelClass(): string
    {
        return PurchaseRequest::class;
    }

    protected function routePrefix(): string
    {
        return 'purchase-requests';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PurchaseRequest::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'orgUnit:id,name',
        ])->withCount('items');

        $this->applyCommonFilters($query, $request);

        if ($priority = (string) $request->query('priority', '')) {
            $query->priority($priority);
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            // "Open" is everything still moving. A rejected request is finished with; an ordered
            // one has become an order and is followed there.
            'open' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            'mine' => $query->where('created_by', $user->getKey()),
            'awaiting' => $query->awaitingDecision(),
            'approved' => $query->statusKey('approved'),
            default => $query,
        };

        /** @var LengthAwarePaginator $purchaseRequests */
        $purchaseRequests = $query
            ->orderByRaw('CASE WHEN required_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('required_by')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('purchase-requests.index', [
            'purchaseRequests' => $purchaseRequests,
            'statuses' => $this->statuses->optionsFor('purchase_request'),
            'projects' => $this->projectOptions($user),
            'priorities' => $this->lists->for('purchase_request.priorities', PurchaseRequest::DEFAULT_PRIORITIES),
            'views' => [
                'open' => 'Open',
                'awaiting' => 'Waiting on a decision',
                'approved' => 'Approved, not yet ordered',
                'mine' => 'Raised by me',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'priority' => $request->query('priority'),
            ],
            'canCreate' => $user->can('create', PurchaseRequest::class),
        ]);
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest): View
    {
        $this->authorize('view', $purchaseRequest);

        $user = $request->user();

        $purchaseRequest->loadMissing([
            'status', 'project', 'orgUnit', 'location', 'creator:id,name',
            'submitter:id,name', 'approver:id,name', 'rejecter:id,name',
            'items', 'rfqs.status:id,label,color', 'purchaseOrders.status:id,label,color',
            'purchaseOrders.vendor:id,name',
        ]);

        return view('purchase-requests.show', [
            'purchaseRequest' => $purchaseRequest,
            'timeline' => $this->activity->timelineFor($purchaseRequest)->limit(25)->get(),
            'transitions' => $this->statuses->available('purchase_request', $purchaseRequest->status_id, $user),
            'workflowInstance' => $purchaseRequest->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $purchaseRequest),
            'canDelete' => $user->can('delete', $purchaseRequest),
            'canSubmit' => $user->can('submit', $purchaseRequest),
            'canApprove' => $user->can('approve', $purchaseRequest),
            'canReject' => $user->can('reject', $purchaseRequest),
            // The button that starts the next link in the chain. Offered only to somebody who may
            // create an enquiry, and only once the request is actually approved.
            'canRaiseEnquiry' => $purchaseRequest->isOrderable() && $user->can('create', Rfq::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PurchaseRequest::class);

        return view('purchase-requests.create', [
            'purchaseRequest' => new PurchaseRequest([
                'confidentiality' => Confidentiality::Internal->value,
                'project_id' => (int) $request->query('project', 0) ?: null,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(PurchaseRequestRequest $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        $data = $request->purchaseRequestAttributes();

        $this->assertPlacementIsReachable($data);

        $purchaseRequest = $this->requests->create($data, $request->user());

        return redirect($this->showRoute($purchaseRequest))
            ->with('status', $purchaseRequest->displayName().' has been raised.');
    }

    public function edit(Request $request, PurchaseRequest $purchaseRequest): View
    {
        $this->authorize('update', $purchaseRequest);

        $purchaseRequest->loadMissing('items');

        return view('purchase-requests.edit', [
            'purchaseRequest' => $purchaseRequest,
            ...$this->formOptions($request),
        ]);
    }

    public function update(PurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);

        $data = $request->purchaseRequestAttributes();

        $this->assertPlacementIsReachable($data);

        $this->requests->update($purchaseRequest, $data, $request->user());

        return redirect($this->showRoute($purchaseRequest))
            ->with('status', $purchaseRequest->displayName().' has been updated.');
    }

    public function destroy(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('delete', $purchaseRequest);

        $name = $purchaseRequest->displayName();

        $this->recordDeletion($purchaseRequest, $request->user(), 'purchase request');

        $purchaseRequest->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ decisions

    /**
     * Send it to whoever decides.
     *
     * Separate from `transition` because submission is not only a status move: the service refuses
     * a request with no lines, and stamps who submitted it and when. Those two facts are what the
     * approver is later shown, and what the segregation rule is measured against.
     */
    public function submit(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('submit', $purchaseRequest);

        try {
            $this->requests->submit($purchaseRequest, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseRequest->displayName().' has been submitted for approval.');
    }

    public function approve(ProcurementDecisionRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('approve', $purchaseRequest);

        try {
            $this->requests->approve($purchaseRequest, $request->user(), $request->comment());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseRequest->displayName().' has been approved.');
    }

    public function reject(ProcurementDecisionRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('reject', $purchaseRequest);

        try {
            $this->requests->reject($purchaseRequest, $request->user(), $request->reason());
        } catch (\DomainException $e) {
            return back()->withErrors(['comment' => $e->getMessage()]);
        }

        return back()->with('status', $purchaseRequest->displayName().' has been rejected.');
    }

    public function transition(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        return $this->moveStatus($request, $purchaseRequest);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'locations' => Location::query()->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all(),
            'priorities' => $this->lists->for('purchase_request.priorities', PurchaseRequest::DEFAULT_PRIORITIES),
            'nextNumber' => $this->numbers->preview('purchase_request'),
        ];
    }
}
