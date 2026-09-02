<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ProcurementDecisionRequest;
use App\Http\Requests\RfqAwardRequest;
use App\Http\Requests\RfqInvitationRequest;
use App\Http\Requests\RfqRequest;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\RfqVendor;
use App\Models\Vendor;
use App\Services\RfqService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The enquiry: what we are asking the market for, and who we asked.
 *
 * THE INVITATION LIST IS NOT PART OF THE FORM. Suppliers are added and removed one at a time,
 * through endpoints of their own, because each addition is an event with a time on it. A list
 * posted wholesale with the rest of the enquiry would let somebody quietly drop a competitor
 * between two saves and leave nothing to show for it — and once quotations exist, removal has to
 * be refused rather than silently reconciled. RfqService::uninvite() is where that refusal lives.
 *
 * ISSUING IS THE LINE. Before it, the enquiry is a draft nobody outside the company has seen and
 * the invitation list can be edited freely. After it, `invited_at` is stamped on everyone and each
 * later addition carries its own later timestamp — which is exactly the question an unsuccessful
 * bidder asks: when was I asked, and was everybody asked at the same time.
 *
 * AWARDING IS ONE ACT OVER EVERY QUOTATION. RfqService::award() sets the winner to accepted and
 * every other live quotation to unsuccessful inside one transaction, so a winner-without-losers or
 * a two-winners state is unreachable. That is why no quotation on the comparison screen carries its
 * own "accept" button.
 */
class RfqController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly RfqService $rfqs,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'rfq';
    }

    protected function modelClass(): string
    {
        return Rfq::class;
    }

    protected function routePrefix(): string
    {
        return 'rfqs';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Rfq::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
        ])->withCount(['items', 'invitations', 'quotations']);

        $this->applyCommonFilters($query, $request);

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'open' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            // Seven days is a working week: long enough to chase a silent supplier, short enough
            // that the list is still worth reading.
            'closing' => $query->closingWithin(7),
            'evaluation' => $query->statusKey('under_evaluation'),
            'awarded' => $query->statusKey('awarded'),
            default => $query,
        };

        /** @var LengthAwarePaginator $rfqs */
        $rfqs = $query
            ->orderByRaw('CASE WHEN closes_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('closes_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('rfqs.index', [
            'rfqs' => $rfqs,
            'statuses' => $this->statuses->optionsFor('rfq'),
            'projects' => $this->projectOptions($user),
            'views' => [
                'open' => 'Open',
                'closing' => 'Closing this week',
                'evaluation' => 'Under evaluation',
                'awarded' => 'Awarded',
                'all' => 'Everything you can see',
            ],
            'filters' => $this->commonFilters($request),
            'canCreate' => $user->can('create', Rfq::class),
        ]);
    }

    public function show(Request $request, Rfq $rfq): View
    {
        $this->authorize('view', $rfq);

        $user = $request->user();

        $rfq->loadMissing([
            'status', 'project', 'orgUnit', 'creator:id,name',
            'issuer:id,name', 'awarder:id,name', 'purchaseRequest:id,number,title',
            'items', 'invitations.vendor:id,name,status_id', 'awardedQuotation:id,number,total,currency',
            'quotations.vendor:id,name', 'quotations.status:id,label,color',
        ]);

        $canInvite = $user->can('invite', $rfq);

        return view('rfqs.show', [
            'rfq' => $rfq,
            'summary' => $rfq->responseSummary(),
            'timeline' => $this->activity->timelineFor($rfq)->limit(25)->get(),
            'transitions' => $this->statuses->available('rfq', $rfq->status_id, $user),
            'workflowInstance' => $rfq->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $rfq),
            'canDelete' => $user->can('delete', $rfq),
            'canIssue' => $user->can('issue', $rfq),
            'canInvite' => $canInvite,
            'canAward' => $user->can('award', $rfq),
            'canCompare' => $user->can('compareAny', Quotation::class),
            'canRecordQuotation' => $user->can('createFor', [Quotation::class, $rfq]),
            // §61 in a dropdown: the invitation picker is the vendor register seen through the
            // inviter's own reach, not a list of every supplier the company uses.
            'vendors' => $canInvite ? $this->vendorOptions($request, $rfq) : [],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Rfq::class);

        $user = $request->user();

        // An enquiry raised from an approved requisition arrives with `?from=`. The requisition is
        // resolved through the SCOPED query, so an id belonging to another tenant — or to a
        // requisition this buyer cannot open — simply does not prefill anything (§75, §84).
        $source = null;

        if ($fromId = (int) $request->query('from', 0)) {
            $source = $this->compiler
                ->apply(PurchaseRequest::query(), $user, 'purchase_request.view')
                ->with('items')
                ->find($fromId);
        }

        return view('rfqs.create', [
            'rfq' => new Rfq([
                'confidentiality' => Confidentiality::Internal->value,
                'title' => $source?->title,
                'project_id' => $source?->project_id ?? ((int) $request->query('project', 0) ?: null),
                'org_unit_id' => $source?->org_unit_id,
                'purchase_request_id' => $source?->isOrderable() ? $source->id : null,
            ]),
            'source' => $source?->isOrderable() ? $source : null,
            ...$this->formOptions($request),
        ]);
    }

    public function store(RfqRequest $request): RedirectResponse
    {
        $this->authorize('create', Rfq::class);

        $data = $request->rfqAttributes();

        $this->assertPlacementIsReachable($data);

        $user = $request->user();

        // Raised FROM a requisition, the enquiry copies its lines and stamps a note on the
        // requisition saying the market has been approached. The requisition is re-read through the
        // scoped query and authorized here rather than trusted from the posted id (§84, §94).
        if ($sourceId = $data['purchase_request_id'] ?? null) {
            $source = $this->compiler
                ->apply(PurchaseRequest::query(), $user, 'purchase_request.view')
                ->with('items')
                ->find($sourceId);

            if ($source === null) {
                return back()->withInput()
                    ->withErrors(['purchase_request_id' => 'That purchase request could not be found.']);
            }

            $this->authorize('view', $source);

            try {
                $rfq = $this->rfqs->fromPurchaseRequest($source, $data, $user);
            } catch (\DomainException $e) {
                return back()->withInput()->withErrors(['purchase_request_id' => $e->getMessage()]);
            }

            return redirect($this->showRoute($rfq))
                ->with('status', $rfq->displayName().' has been raised from '.$source->number.'.');
        }

        $rfq = $this->rfqs->create($data, $user);

        return redirect($this->showRoute($rfq))
            ->with('status', $rfq->displayName().' has been raised.');
    }

    public function edit(Request $request, Rfq $rfq): View
    {
        $this->authorize('update', $rfq);

        $rfq->loadMissing('items');

        return view('rfqs.edit', [
            'rfq' => $rfq,
            'source' => null,
            ...$this->formOptions($request),
        ]);
    }

    public function update(RfqRequest $request, Rfq $rfq): RedirectResponse
    {
        $this->authorize('update', $rfq);

        $data = $request->rfqAttributes();

        $this->assertPlacementIsReachable($data);

        $this->rfqs->update($rfq, $data, $request->user());

        return redirect($this->showRoute($rfq))
            ->with('status', $rfq->displayName().' has been updated.');
    }

    public function destroy(Request $request, Rfq $rfq): RedirectResponse
    {
        $this->authorize('delete', $rfq);

        $name = $rfq->displayName();

        $this->recordDeletion($rfq, $request->user(), 'enquiry');

        $rfq->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ the invitation list

    /**
     * Ask one more supplier.
     *
     * The vendor is resolved through `vendor.view` rather than by id alone. Somebody who cannot
     * open the supplier register must not be able to add a supplier to an enquiry by guessing a
     * number, and the failure reads the same whether the id belongs to another tenant or to a
     * supplier out of this buyer's reach (§61, §75, §94).
     */
    public function invite(RfqInvitationRequest $request, Rfq $rfq): RedirectResponse
    {
        $this->authorize('invite', $rfq);

        $user = $request->user();

        $vendor = $this->compiler
            ->apply(Vendor::query(), $user, 'vendor.view')
            ->find((int) $request->validated('vendor_id'));

        if ($vendor === null) {
            return back()->withErrors(['vendor_id' => 'That supplier could not be found.']);
        }

        try {
            $this->rfqs->invite($rfq, $vendor, $user, $request->validated('notes'));
        } catch (\DomainException $e) {
            return back()->withErrors(['vendor_id' => $e->getMessage()]);
        }

        return back()->with('status', $vendor->name.' has been invited to quote.');
    }

    /**
     * Take a supplier back off the list.
     *
     * Route-model-bound on the INVITATION and then checked against this enquiry, because an
     * invitation id from a different enquiry would otherwise be removable by anybody holding rights
     * on this one. The service refuses outright once the supplier has quoted.
     */
    public function uninvite(Request $request, Rfq $rfq, RfqVendor $invitation): RedirectResponse
    {
        $this->authorize('invite', $rfq);

        abort_unless((int) $invitation->rfq_id === (int) $rfq->getKey(), 404);

        try {
            $this->rfqs->uninvite($invitation, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['vendor_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The supplier has been removed from the enquiry.');
    }

    /** A supplier who has said no. Recorded, because silence and a decline are different facts. */
    public function decline(ProcurementDecisionRequest $request, Rfq $rfq, RfqVendor $invitation): RedirectResponse
    {
        $this->authorize('invite', $rfq);

        abort_unless((int) $invitation->rfq_id === (int) $rfq->getKey(), 404);

        $this->rfqs->recordDecline($invitation, $request->user(), $request->comment());

        return back()->with('status', 'The decline has been recorded.');
    }

    // ------------------------------------------------------------------ issue and award

    public function issue(Request $request, Rfq $rfq): RedirectResponse
    {
        $this->authorize('issue', $rfq);

        try {
            $this->rfqs->issue($rfq, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $rfq->displayName().' has been issued to the invited suppliers.');
    }

    /**
     * Pick the winner.
     *
     * The quotation is re-read through the scoped query and then checked against this enquiry: a
     * posted id from another enquiry is the obvious way to award a contract to a supplier who never
     * bid for it. Whether the award needs a written reason — too few quotations received — is
     * decided in RfqService, not here, because a form field cannot be trusted to say so (§84).
     */
    public function award(RfqAwardRequest $request, Rfq $rfq): RedirectResponse
    {
        $this->authorize('award', $rfq);

        $user = $request->user();

        $quotation = $this->compiler
            ->apply(Quotation::query(), $user, 'quotation.view')
            ->where('rfq_id', $rfq->getKey())
            ->find((int) $request->validated('quotation_id'));

        if ($quotation === null) {
            return back()->withErrors(['quotation_id' => 'That quotation could not be found on this enquiry.']);
        }

        try {
            $this->rfqs->award($rfq, $quotation, $user, $request->validated('reason'));
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $quotation->displayName().' has been awarded.');
    }

    public function transition(Request $request, Rfq $rfq): RedirectResponse
    {
        return $this->moveStatus($request, $rfq);
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'purchaseRequests' => $this->compiler
                ->apply(PurchaseRequest::query(), $user, 'purchase_request.view')
                ->statusKey('approved')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'title'])
                ->mapWithKeys(fn (PurchaseRequest $p) => [$p->id => $p->number.' — '.$p->title])
                ->all(),
            'nextNumber' => $this->numbers->preview('rfq'),
        ];
    }

    /**
     * Suppliers this buyer may see, minus the ones already on the list.
     *
     * Blacklisted and expired suppliers are still offered, because `Vendor::canBeUsed()` gives a
     * reason and the service refuses with it — a supplier silently missing from a picker teaches
     * nobody why, and the buyer chases the absence instead of reading the reason.
     *
     * @return array<int,string>
     */
    private function vendorOptions(Request $request, Rfq $rfq): array
    {
        $invited = $rfq->relationLoaded('invitations')
            ? $rfq->invitations->pluck('vendor_id')->all()
            : $rfq->invitations()->pluck('vendor_id')->all();

        return $this->compiler
            ->apply(Vendor::query(), $request->user(), 'vendor.view')
            ->whereKeyNot($invited)
            ->orderBy('name')->limit(500)
            ->pluck('name', 'id')->all();
    }
}
