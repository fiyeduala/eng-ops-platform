<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\VendorBankDetailsRequest;
use App\Http\Requests\VendorRequest;
use App\Models\Vendor;
use App\Services\VendorService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Who we buy from, and whether we are still allowed to.
 *
 * THE REGISTER ANSWERS ONE QUESTION AT THE MOMENT IT IS ASKED: can we raise a purchase order to
 * this company today. Everything on the index page serves that — the status chip, the blacklist
 * flag, the prequalification expiry — because the alternative is a buyer discovering on the morning
 * of an order that the supplier's assessment lapsed in March.
 *
 * THREE THINGS ARE FENCED OFF FROM ORDINARY EDITING, each for a different reason:
 *
 *   · BANK DETAILS have their own endpoint, their own request and their own permission. This is the
 *     platform's principal fraud surface — see VendorBankDetailsRequest, which argues it at length —
 *     and it is also the only place in this application where READING is gated as tightly as
 *     writing, because a stored account number is worth stealing on its own (§67, §68).
 *   · BLACKLISTING demands a reason and moves the status with it, so the register can always answer
 *     "why". A boolean on the edit form could be flipped back by the person who was caught.
 *   · RATINGS are the mean of recorded evaluations. Nobody types their friend a four.
 *
 * `show()` PASSES THE BANK BLOCK AS A SEPARATE FLAG rather than relying on the Blade template to
 * remember. A view that decides for itself is a view that leaks the moment somebody copies the
 * partial into a print layout (§6).
 */
class VendorController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly VendorService $vendors,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'vendor';
    }

    protected function modelClass(): string
    {
        return Vendor::class;
    }

    protected function routePrefix(): string
    {
        return 'vendors';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Vendor::class);

        $user = $request->user();
        $warningDays = $this->warningDays();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'orgUnit:id,name',
        ])->withCount('contacts');

        $query->search((string) $request->query('q', ''));

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        foreach (['vendor_type', 'category'] as $column) {
            if ($value = (string) $request->query($column, '')) {
                $query->where($column, $value);
            }
        }

        $view = (string) $request->query('view', 'usable');

        match ($view) {
            // The default, because it is the question a buyer actually has.
            'usable' => $query->usable(),
            'expiring' => $query->expiringWithin($warningDays),
            'blacklisted' => $query->where('is_blacklisted', true),
            'inactive' => $query->where('is_active', false),
            default => $query,
        };

        /** @var LengthAwarePaginator $vendors */
        $vendors = $query
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('vendors.index', [
            'vendors' => $vendors,
            'statuses' => $this->statuses->optionsFor('vendor'),
            'types' => $this->lists->for('vendor.types', Vendor::DEFAULT_TYPES),
            'categories' => $this->lists->for('vendor.categories', Vendor::DEFAULT_CATEGORIES),
            'warningDays' => $warningDays,
            'views' => [
                'usable' => 'Available to order from',
                'expiring' => 'Prequalification expiring',
                'blacklisted' => 'Blacklisted',
                'inactive' => 'Inactive',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                'q' => $request->query('q'),
                'status' => $request->query('status'),
                'vendor_type' => $request->query('vendor_type'),
                'category' => $request->query('category'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Vendor::class),
        ]);
    }

    public function show(Request $request, Vendor $vendor): View
    {
        $this->authorize('view', $vendor);

        $user = $request->user();

        $vendor->loadMissing([
            'status', 'orgUnit', 'creator:id,name', 'blacklister:id,name',
            'contacts' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('name'),
        ]);

        // Contracts are listed only where the reader can open them. A contract defaults to
        // confidential, so this is not a cosmetic filter — it is the difference between a supplier
        // page and a list of what every subcontractor is being paid (§61, §68).
        $contracts = $vendor->contracts()
            ->with('status:id,label,color')
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($contract) => $user->can('view', $contract))
            ->values();

        return view('vendors.show', [
            'vendor' => $vendor,
            'contracts' => $contracts,
            'timeline' => $this->activity->timelineFor($vendor)->limit(25)->get(),
            'transitions' => $this->statuses->available('vendor', $vendor->status_id, $user),
            'workflowInstance' => $vendor->currentWorkflowInstance(),
            'warningDays' => $this->warningDays(),
            'canEdit' => $user->can('update', $vendor),
            'canDelete' => $user->can('delete', $vendor),
            'canViewBank' => $user->can('viewBankDetails', $vendor),
            'canEditBank' => $user->can('updateBankDetails', $vendor),
            'canBlacklist' => $user->can('blacklist', $vendor),
            'canLiftBlacklist' => $user->can('liftBlacklist', $vendor),
            'canPrequalify' => $user->can('prequalify', $vendor),
            'canEvaluate' => $user->can('evaluate', $vendor),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Vendor::class);

        return view('vendors.create', [
            'vendor' => new Vendor([
                'confidentiality' => Confidentiality::Internal->value,
                'is_active' => true,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        $this->authorize('create', Vendor::class);

        $data = $request->vendorAttributes();

        $this->assertPlacementIsReachable($data);

        $vendor = $this->vendors->create($data, $request->user());

        return redirect($this->showRoute($vendor))
            ->with('status', $vendor->displayName().' has been added to the register.');
    }

    public function edit(Request $request, Vendor $vendor): View
    {
        $this->authorize('update', $vendor);

        return view('vendors.edit', [
            'vendor' => $vendor,
            ...$this->formOptions($request),
        ]);
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('update', $vendor);

        $data = $request->vendorAttributes();

        $this->assertPlacementIsReachable($data);

        $this->vendors->update($vendor, $data, $request->user());

        return redirect($this->showRoute($vendor))
            ->with('status', $vendor->displayName().' has been updated.');
    }

    public function destroy(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('delete', $vendor);

        $name = $vendor->displayName();

        $this->recordDeletion($vendor, $request->user(), 'vendor');

        $vendor->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been removed from the register.');
    }

    // ------------------------------------------------------------------ bank details

    /**
     * The form itself is behind `viewBankDetails`, not merely the save.
     *
     * Serving an edit form pre-filled with the current account number to somebody who may not read
     * it would hand over exactly the value the permission exists to protect.
     */
    public function editBankDetails(Request $request, Vendor $vendor): View
    {
        $this->authorize('updateBankDetails', $vendor);

        return view('vendors.bank', ['vendor' => $vendor]);
    }

    public function updateBankDetails(VendorBankDetailsRequest $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('updateBankDetails', $vendor);

        $this->vendors->updateBankDetails(
            $vendor,
            $request->bankAttributes(),
            $request->user(),
            (string) $request->validated('reason'),
        );

        return redirect($this->showRoute($vendor))
            ->with('status', 'The bank details held for '.$vendor->name.' have been updated.');
    }

    // ------------------------------------------------------------------ standing

    /**
     * Bar the company from new work.
     *
     * The reason is required by the request AND by the service. Two checks for one rule, because
     * this one is reached from a workflow action as well as from this form, and a validator only
     * guards the door it is nailed to (§84).
     */
    public function blacklist(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('blacklist', $vendor);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->vendors->blacklist($vendor, (string) $validated['reason'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $vendor->name.' has been blacklisted.');
    }

    public function liftBlacklist(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('liftBlacklist', $vendor);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->vendors->liftBlacklist($vendor, (string) $validated['reason'], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $vendor->name.' has been returned to the register for review.');
    }

    /** File the assessment. Approving on the strength of it is a status transition, and separate. */
    public function prequalify(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('prequalify', $vendor);

        $validated = $request->validate([
            'prequalified_on' => ['nullable', 'date'],
            'prequalification_expires_on' => ['nullable', 'date', 'after:prequalified_on'],
        ]);

        $this->vendors->recordPrequalification(
            $vendor,
            $validated['prequalified_on'] ?? null,
            $validated['prequalification_expires_on'] ?? null,
            $request->user(),
        );

        return back()->with('status', 'The prequalification held for '.$vendor->name.' has been recorded.');
    }

    /**
     * Fold one assessment into the running score.
     *
     * A mean, not a replacement — one poor delivery moves a long-standing supplier's score without
     * erasing five years of good ones, and `rated_count` is kept so a screen can say "3.8 from 14"
     * rather than a bare number people argue about.
     */
    public function evaluate(Request $request, Vendor $vendor): RedirectResponse
    {
        $this->authorize('evaluate', $vendor);

        $validated = $request->validate([
            'score' => ['required', 'numeric', 'between:0,5'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->vendors->recordRating(
                $vendor,
                (float) $validated['score'],
                $request->user(),
                $validated['note'] ?? null,
            );
        } catch (\DomainException $e) {
            return back()->withErrors(['score' => $e->getMessage()]);
        }

        return back()->with('status', 'The assessment of '.$vendor->name.' has been recorded.');
    }

    public function transition(Request $request, Vendor $vendor): RedirectResponse
    {
        return $this->moveStatus($request, $vendor);
    }

    // ------------------------------------------------------------------ internals

    private function warningDays(): int
    {
        return max(7, (int) $this->settings->get('vendor.prequalification_warning_days', 60));
    }

    /** @return array<string,mixed> */
    private function formOptions(Request $request): array
    {
        return [
            'units' => $this->unitOptions($request->user()),
            'confidentialities' => Confidentiality::options(),
            'types' => $this->lists->for('vendor.types', Vendor::DEFAULT_TYPES),
            'categories' => $this->lists->for('vendor.categories', Vendor::DEFAULT_CATEGORIES),
            'nextNumber' => $this->numbers->preview('vendor'),
        ];
    }
}
