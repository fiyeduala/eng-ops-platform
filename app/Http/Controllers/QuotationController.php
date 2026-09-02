<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\QuotationRequest;
use App\Http\Requests\QuotationScoreRequest;
use App\Models\Document;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\QuotationScore;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Services\QuotationService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What the suppliers came back with — and the screen where they are compared.
 *
 * A QUOTATION DEFAULTS TO CONFIDENTIAL. A live tender is the one place in this application where
 * leaking a figure to the wrong colleague can invalidate the process rather than merely embarrass
 * somebody: a buyer who knows what the other three bid is a buyer who can be leaned on. Gate 5 in
 * ScopedPolicy is doing the work, and Quotation::defaultConfidentiality() is why it does it here
 * and not on the enquiry.
 *
 * SCORES ARE PERSONAL. QuotationService::recordScores() writes only the rows belonging to the
 * signed-in evaluator, and QuotationScoreRequest has no field that could say whose marks these are
 * (§84). One evaluator therefore cannot overwrite another's marks, which is the whole point of
 * having more than one evaluator.
 *
 * THE COMPARISON IS A READ. It shows the totals every quotation on one enquiry carries, converted
 * at the rate stored on each row, plus whatever scoring exists. It awards nothing: awarding is one
 * transaction over the whole enquiry and lives on RfqController.
 *
 * WHAT THE COMPARISON DOES NOT SHOW. Quotations the reader may not see. The list is built through
 * the scoped query like every other list in the platform, so an evaluator with narrow reach sees a
 * shorter table rather than a fuller one (§61).
 */
class QuotationController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly QuotationService $quotations,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'quotation';
    }

    protected function modelClass(): string
    {
        return Quotation::class;
    }

    protected function routePrefix(): string
    {
        return 'quotations';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quotation::class);

        $user = $request->user();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'vendor:id,name',
            'rfq:id,number,title',
        ])->withCount('items');

        $this->applyCommonFilters($query, $request);

        $query->forVendor($request->query('vendor'));

        if ($rfqId = (int) $request->query('rfq', 0)) {
            $query->where('rfq_id', $rfqId);
        }

        $view = (string) $request->query('view', 'open');

        match ($view) {
            'open' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            // Validity is a date, not a status — see the absence of an `expired` state in the
            // status catalogue — so "expiring" is a date query.
            'expiring' => $query->whereNotNull('valid_until')
                ->whereDate('valid_until', '<=', now()->addDays(14)->toDateString()),
            'accepted' => $query->statusKey('accepted'),
            default => $query,
        };

        /** @var LengthAwarePaginator $quotations */
        $quotations = $query
            ->orderByDesc('quoted_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('quotations.index', [
            'quotations' => $quotations,
            'statuses' => $this->statuses->optionsFor('quotation'),
            'projects' => $this->projectOptions($user),
            'vendors' => $this->vendorOptions($user),
            'views' => [
                'open' => 'Open',
                'expiring' => 'Validity running out',
                'accepted' => 'Accepted',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request),
                'vendor' => $request->query('vendor'),
                'rfq' => $request->query('rfq'),
            ],
            'canCreate' => $user->can('create', Quotation::class),
        ]);
    }

    public function show(Request $request, Quotation $quotation): View
    {
        $this->authorize('view', $quotation);

        $user = $request->user();

        $quotation->loadMissing([
            'status', 'vendor', 'rfq:id,number,title,status_id', 'rfq.status:id,label,color',
            'creator:id,name', 'items', 'document',
            'scores.evaluator:id,name',
        ]);

        $document = $quotation->document;
        $canScore = $user->can('scoreFor', [QuotationScore::class, $quotation]);

        return view('quotations.show', [
            'quotation' => $quotation,
            'timeline' => $this->activity->timelineFor($quotation)->limit(25)->get(),
            'transitions' => $this->statuses->available('quotation', $quotation->status_id, $user),
            'workflowInstance' => $quotation->currentWorkflowInstance(),
            'canEdit' => $user->can('update', $quotation),
            'canDelete' => $user->can('delete', $quotation),
            'canScore' => $canScore,
            'canCompare' => $user->can('compare', $quotation),
            // The quoted file lives in private storage. Linking it to somebody who cannot open it
            // announces the file rather than sharing it (§67, §68).
            'canOpenDocument' => $document !== null && $user->can('view', $document),
            'criteria' => $canScore ? $this->criteria($quotation) : [],
            'scoreOutOf' => $this->scoreOutOf($quotation),
            // Only this evaluator's own marks are prefilled into the form. Everybody else's are
            // shown as a read-only panel, gated by QuotationScorePolicy on each row.
            'myScores' => $quotation->scores
                ->where('evaluated_by', $user->getKey())
                ->keyBy('criterion_key'),
            'canRaiseOrder' => $quotation->wasAwarded() && $user->can('create', PurchaseOrder::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Quotation::class);

        $user = $request->user();

        // Reached from an enquiry nine times out of ten. The enquiry is resolved through the scoped
        // query, so a posted id belonging to another tenant prefills nothing (§75, §84).
        $rfq = null;

        if ($rfqId = (int) $request->query('rfq', 0)) {
            $rfq = $this->compiler->apply(Rfq::query(), $user, 'rfq.view')
                ->with('items')
                ->find($rfqId);
        }

        return view('quotations.create', [
            'quotation' => new Quotation([
                'confidentiality' => (new Quotation)->defaultConfidentiality()->value,
                'rfq_id' => $rfq?->id,
                'quoted_on' => now()->toDateString(),
            ]),
            'rfq' => $rfq,
            ...$this->formOptions($request, $rfq),
        ]);
    }

    public function store(QuotationRequest $request): RedirectResponse
    {
        $this->authorize('create', Quotation::class);

        $data = $request->quotationAttributes();

        // A quotation is filed against an enquiry, and a person who may record quotations in
        // general still must not attach one to an enquiry they cannot open. That is `createFor`,
        // and it is exactly the shape of an IDOR on a hidden `rfq_id` (§84, §94).
        if ($rfqId = $data['rfq_id'] ?? null) {
            $rfq = $this->compiler->apply(Rfq::query(), $request->user(), 'rfq.view')->find($rfqId);

            if ($rfq === null) {
                return back()->withInput()->withErrors(['rfq_id' => 'That enquiry could not be found.']);
            }

            $this->authorize('createFor', [Quotation::class, $rfq]);
        }

        try {
            $quotation = $this->quotations->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['vendor_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($quotation))
            ->with('status', $quotation->displayName().' has been recorded.');
    }

    public function edit(Request $request, Quotation $quotation): View
    {
        $this->authorize('update', $quotation);

        $quotation->loadMissing('items', 'rfq.items');

        return view('quotations.edit', [
            'quotation' => $quotation,
            'rfq' => $quotation->rfq,
            ...$this->formOptions($request, $quotation->rfq),
        ]);
    }

    public function update(QuotationRequest $request, Quotation $quotation): RedirectResponse
    {
        $this->authorize('update', $quotation);

        try {
            $this->quotations->update($quotation, $request->quotationAttributes(), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['vendor_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($quotation))
            ->with('status', $quotation->displayName().' has been updated.');
    }

    public function destroy(Request $request, Quotation $quotation): RedirectResponse
    {
        $this->authorize('delete', $quotation);

        $name = $quotation->displayName();

        $this->recordDeletion($quotation, $request->user(), 'quotation');

        $quotation->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ evaluation

    /**
     * Record one evaluator's marks.
     *
     * The evaluator is the signed-in user and nothing else. There is no field on the form that
     * could name somebody, and the service writes only rows already belonging to this person, so a
     * crafted post cannot enter marks in a colleague's name (§84).
     */
    public function score(QuotationScoreRequest $request, Quotation $quotation): RedirectResponse
    {
        $this->authorize('scoreFor', [QuotationScore::class, $quotation]);

        $written = $this->quotations->recordScores($quotation, $request->user(), $request->marks());

        return back()->with('status', $written === 0
            ? 'Your marks have been cleared.'
            : 'Your marks have been recorded.');
    }

    /**
     * One enquiry, every quotation on it, side by side.
     *
     * The enquiry is authorized first and the quotations are then read through the scoped query, so
     * this page can never be a way round `quotation.view`: an evaluator with narrow reach sees a
     * shorter table, not somebody else's fuller one (§61).
     *
     * Totals are converted using the rate STORED ON EACH ROW, so the comparison reads the same next
     * year as it did at the meeting. Nothing here is revalued.
     */
    public function compare(Request $request, Rfq $rfq): View
    {
        $this->authorize('view', $rfq);
        $this->authorize('compareAny', Quotation::class);

        $user = $request->user();

        $rfq->loadMissing('status', 'items', 'invitations.vendor:id,name');

        $quotations = $this->scopedQuery($user)
            ->where('rfq_id', $rfq->getKey())
            ->with(['status:id,label,color', 'vendor:id,name', 'items', 'scores'])
            ->orderBy('total')
            ->get();

        $criteria = $this->lists->for(
            'procurement.evaluation_criteria',
            Quotation::DEFAULT_CRITERIA,
            $rfq->organization_id,
        );

        // Computed once here rather than in the view, because Blade calling evaluationScore() in a
        // loop would be a lazy-load waiting to happen and the ranking wants the numbers anyway.
        $marks = $quotations->mapWithKeys(fn (Quotation $q) => [$q->id => $q->evaluationScore()]);

        return view('quotations.compare', [
            'rfq' => $rfq,
            'quotations' => $quotations,
            'criteria' => $criteria,
            'marks' => $marks,
            'lowest' => $quotations
                ->reject(fn (Quotation $q) => in_array($q->statusKey(), ['withdrawn'], true))
                ->sortBy(fn (Quotation $q) => (float) $q->totalInBaseCurrency())
                ->first()?->id,
            'baseCurrency' => $user->organization?->currency,
            'canAward' => $user->can('award', $rfq),
            'minimumQuotations' => $this->settings->int(
                'procurement.minimum_quotations', 3, $rfq->organization_id,
            ),
        ]);
    }

    public function transition(Request $request, Quotation $quotation): RedirectResponse
    {
        return $this->moveStatus($request, $quotation);
    }

    // ------------------------------------------------------------------ internals

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Rfq $rfq = null): array
    {
        $user = $request->user();

        return [
            // Only the invited suppliers when the quotation belongs to an enquiry: a quotation from
            // a company that was never asked is a process failure, and the service refuses it. The
            // full register is offered only for an unsolicited quotation with no enquiry behind it.
            'vendors' => $rfq !== null
                ? $this->invitedVendorOptions($user, $rfq)
                : $this->vendorOptions($user),
            'rfqs' => $this->compiler->apply(Rfq::query(), $user, 'rfq.view')
                ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'title'])
                ->mapWithKeys(fn (Rfq $r) => [$r->id => $r->number.' — '.$r->title])
                ->all(),
            'documents' => $this->compiler->apply(Document::query(), $user, 'document.view')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'title'])
                ->mapWithKeys(fn (Document $d) => [$d->id => $d->number.' — '.$d->title])
                ->all(),
            'confidentialities' => Confidentiality::options(),
            'nextNumber' => $this->numbers->preview('quotation'),
        ];
    }

    /** @return array<int,string> */
    private function vendorOptions(User $user): array
    {
        return $this->compiler->apply(Vendor::query(), $user, 'vendor.view')
            ->orderBy('name')->limit(500)->pluck('name', 'id')->all();
    }

    /** @return array<int,string> */
    private function invitedVendorOptions(User $user, Rfq $rfq): array
    {
        return $this->compiler->apply(Vendor::query(), $user, 'vendor.view')
            ->whereIn('id', $rfq->invitations()->select('vendor_id'))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string,string> */
    private function criteria(Quotation $quotation): array
    {
        return $this->lists->for(
            'procurement.evaluation_criteria',
            Quotation::DEFAULT_CRITERIA,
            $quotation->organization_id,
        );
    }

    private function scoreOutOf(Quotation $quotation): int
    {
        return max(1, $this->settings->int('procurement.score_out_of', 10, $quotation->organization_id));
    }
}
