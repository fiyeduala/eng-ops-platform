<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ContractRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Document;
use App\Models\Vendor;
use App\Services\ContractService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What was agreed, with whom, for how much, and until when.
 *
 * A CONTRACT DEFAULTS TO CONFIDENTIAL — the only register in this application that does. The value
 * is the single most commercially damaging figure a company holds about itself: the subcontractor
 * whose price it names is frequently somebody's former employer, and a rate a competitor would pay
 * for is one screenshot away. Gate 5 in ScopedPolicy therefore does most of the work, and a company
 * widens it by granting unit reach or an ACL row rather than by editing code.
 *
 * SEEING A CONTRACT AND SEEING ITS NUMBERS ARE TWO QUESTIONS. `canViewFinancials` is passed to the
 * views separately, gated by ContractPolicy::viewFinancials(), because site management routinely
 * needs to know that a subcontract exists, who it is with and when it expires while having no
 * business knowing what it is worth. The same shape IncidentPersonPolicy uses for medical details.
 *
 * BOTH DIRECTIONS IN ONE REGISTER. A contract with a client is revenue and a contract with a
 * subcontractor is spend; they are the same object seen from opposite ends, and `direction` plus the
 * `party` morph is what separates them. ContractService resolves that morph through a two-entry
 * whitelist and a tenant-scoped lookup — never `Relation::getMorphedModel()`, which would accept
 * `user` (§75, §84).
 *
 * EXPIRY IS NOT A STATUS. A contract passes its end date whether or not anybody clicks anything, so
 * the "expiring" view is a date query, not a state. What the status graph records is the part a
 * person is responsible for: drafted, out for signature, live, suspended, completed, terminated.
 */
class ContractController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly ContractService $contracts,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'contract';
    }

    protected function modelClass(): string
    {
        return Contract::class;
    }

    protected function routePrefix(): string
    {
        return 'contracts';
    }

    // ------------------------------------------------------------------ register

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Contract::class);

        $user = $request->user();
        $warningDays = $this->warningDays();

        $query = $this->scopedQuery($user)->with([
            'status:id,label,color,is_terminal',
            'project:id,name,number',
            'owner:id,name',
            'party',
        ]);

        $this->applyCommonFilters($query, $request);

        if ($direction = (string) $request->query('direction', '')) {
            $query->direction($direction);
        }

        if ($type = (string) $request->query('contract_type', '')) {
            $query->where('contract_type', $type);
        }

        $view = (string) $request->query('view', 'live');

        match ($view) {
            'live' => $query->whereHas('status', fn ($q) => $q->where('is_terminal', false)),
            'expiring' => $query->expiringWithin($warningDays),
            'retention' => $query->where('retention_held', '>', 0),
            default => $query,
        };

        /** @var LengthAwarePaginator $contracts */
        $contracts = $query
            ->orderByRaw('CASE WHEN ends_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ends_on')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('contracts.index', [
            'contracts' => $contracts,
            'statuses' => $this->statuses->optionsFor('contract'),
            'projects' => $this->projectOptions($user),
            'types' => $this->lists->for('contract.types', Contract::DEFAULT_TYPES),
            'warningDays' => $warningDays,
            'views' => [
                'live' => 'Live',
                'expiring' => 'Expiring soon',
                'retention' => 'Holding retention',
                'all' => 'Everything you can see',
            ],
            'filters' => [
                ...$this->commonFilters($request, 'live'),
                'direction' => $request->query('direction'),
                'contract_type' => $request->query('contract_type'),
            ],
            // The permission is asked once for the register rather than row by row: financial
            // visibility is a permission, not a record property, so a per-row check would be the
            // same answer thirty times.
            'canViewFinancials' => $user->can('viewAnyFinancials', Contract::class),
            'canCreate' => $user->can('create', Contract::class),
        ]);
    }

    public function show(Request $request, Contract $contract): View
    {
        $this->authorize('view', $contract);

        $user = $request->user();

        $contract->loadMissing([
            'status', 'project', 'orgUnit', 'owner:id,name', 'creator:id,name', 'party', 'document',
        ]);

        // The signed document is linked only where the reader may open it. Contract PDFs live in
        // private storage behind DocumentController, so a link they cannot follow would announce
        // the file rather than share it (§67, §68).
        $document = $contract->document;

        return view('contracts.show', [
            'contract' => $contract,
            'timeline' => $this->activity->timelineFor($contract)->limit(25)->get(),
            'transitions' => $this->statuses->available('contract', $contract->status_id, $user),
            'workflowInstance' => $contract->currentWorkflowInstance(),
            'warningDays' => $this->warningDays(),
            'canOpenDocument' => $document !== null && $user->can('view', $document),
            'canViewFinancials' => $user->can('viewFinancials', $contract),
            'canEdit' => $user->can('update', $contract),
            'canDelete' => $user->can('delete', $contract),
            'canApprove' => $user->can('approve', $contract),
            // Moving retention moves money, so it takes `contract.manage` rather than `edit`.
            'canMoveRetention' => $user->can('manage', $contract),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Contract::class);

        $direction = (string) $request->query('direction', 'client');

        return view('contracts.create', [
            'contract' => new Contract([
                'confidentiality' => Confidentiality::Confidential->value,
                'direction' => in_array($direction, ['client', 'vendor'], true) ? $direction : 'client',
                'project_id' => (int) $request->query('project', 0) ?: null,
                'retention_percent' => $this->settings->get('contract.default_retention_percent'),
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(ContractRequest $request): RedirectResponse
    {
        $this->authorize('create', Contract::class);

        $data = $request->contractAttributes();

        $this->assertPlacementIsReachable($data);

        try {
            $contract = $this->contracts->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['party_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($contract))
            ->with('status', $contract->displayName().' has been registered.');
    }

    public function edit(Request $request, Contract $contract): View
    {
        $this->authorize('update', $contract);

        return view('contracts.edit', [
            'contract' => $contract,
            ...$this->formOptions($request),
        ]);
    }

    public function update(ContractRequest $request, Contract $contract): RedirectResponse
    {
        $this->authorize('update', $contract);

        $data = $request->contractAttributes();

        $this->assertPlacementIsReachable($data);

        try {
            $this->contracts->update($contract, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['party_id' => $e->getMessage()]);
        }

        return redirect($this->showRoute($contract))
            ->with('status', $contract->displayName().' has been updated.');
    }

    public function destroy(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('delete', $contract);

        $name = $contract->displayName();

        $this->recordDeletion($contract, $request->user(), 'contract');

        $contract->delete();

        return redirect($this->indexRoute())->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ retention

    /**
     * Hold back a slice, or give some of it back.
     *
     * ONE ENDPOINT, TWO DIRECTIONS, because the two are the same act with a sign and splitting them
     * would double the routes, the policy calls and the places to forget the balance check. The
     * direction arrives as a named movement rather than as a signed number, so a negative "hold"
     * cannot be posted as a release nobody authorized.
     */
    public function moveRetention(Request $request, Contract $contract): RedirectResponse
    {
        $this->authorize('manage', $contract);

        $validated = $request->validate([
            'movement' => ['required', 'in:hold,release'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $amount = (float) $validated['amount'];
        $note = $validated['note'] ?? null;

        try {
            $validated['movement'] === 'hold'
                ? $this->contracts->addRetention($contract, $amount, $request->user(), $note)
                : $this->contracts->releaseRetention($contract, $amount, $request->user(), $note);
        } catch (\DomainException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('status', $validated['movement'] === 'hold'
            ? 'Retention held against '.$contract->number.'.'
            : 'Retention released against '.$contract->number.'.');
    }

    public function transition(Request $request, Contract $contract): RedirectResponse
    {
        return $this->moveStatus($request, $contract);
    }

    // ------------------------------------------------------------------ internals

    private function warningDays(): int
    {
        return max(7, (int) $this->settings->get('contract.expiry_warning_days', 90));
    }

    /**
     * The counterparties, both sides, each scoped by its own register's `view` permission.
     *
     * §61 in a dropdown: somebody who cannot open the vendor register does not get a list of every
     * supplier the company uses simply because a select box is convenient.
     *
     * @return array<string,mixed>
     */
    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'types' => $this->lists->for('contract.types', Contract::DEFAULT_TYPES),
            'clients' => $this->compiler
                ->apply(Client::query(), $user, 'client.view')
                ->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
            'vendors' => $this->compiler
                ->apply(Vendor::query(), $user, 'vendor.view')
                ->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
            // The signed copy, scoped by `document.view` for the same reason as the two lists
            // above: a select box is not a route around the document register's access rules.
            'documents' => $this->compiler
                ->apply(Document::query(), $user, 'document.view')
                ->orderByDesc('id')->limit(200)
                ->get(['id', 'number', 'title'])
                ->mapWithKeys(fn (Document $d) => [$d->id => $d->number.' — '.$d->title])
                ->all(),
            'nextNumber' => $this->numbers->preview('contract'),
        ];
    }
}
