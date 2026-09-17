<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\IncomeRequest;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Income;
use App\Models\Payment;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\IncomeService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Money the company is owed: drafted, issued to the client, then received through payments.
 * `part_received` and `received` are never chosen here — they follow from recorded receipts.
 */
class IncomeController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly IncomeService $incomes,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'income';
    }

    protected function modelClass(): string
    {
        return Income::class;
    }

    protected function routePrefix(): string
    {
        return 'incomes';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Income::class);
        $user = $request->user();
        $query = $this->scopedQuery($user)->with(['status', 'project', 'client']);
        $this->applyCommonFilters($query, $request);

        return view('incomes.index', [
            'incomes' => $query->orderByDesc('income_date')->orderByDesc('id')->paginate(40)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('income'),
            'projects' => $this->projectOptions($user),
            'filters' => $this->commonFilters($request, 'all'),
            'canCreate' => $user->can('create', Income::class),
        ]);
    }

    public function show(Request $request, Income $income): View
    {
        $this->authorize('view', $income);
        $user = $request->user();
        $income->load(['status', 'project', 'orgUnit', 'client', 'contract', 'taxCode', 'creator']);

        return view('incomes.show', [
            'income' => $income,
            'payments' => $income->payments()->visibleTo($user, 'payment.view')->with('status')->latest('id')->get(),
            'timeline' => $this->activity->timelineFor($income)->limit(25)->get(),
            'canEdit' => $user->can('update', $income),
            'canDelete' => $user->can('delete', $income),
            'canIssue' => $user->can('issue', $income),
            'canManage' => $user->can('manage', $income),
            'canReceive' => $income->isReceivable() && $user->can('create', Payment::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Income::class);

        return view('incomes.create', [
            'income' => new Income([
                'income_date' => now()->toDateString(),
                'confidentiality' => Confidentiality::Internal->value,
            ]),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(IncomeRequest $request): RedirectResponse
    {
        $this->authorize('create', Income::class);
        $data = $request->validated();
        $this->assertReachable($data, $request->user());

        try {
            $income = $this->incomes->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['contract_id' => $e->getMessage()]);
        }

        return redirect()->route('incomes.show', $income)->with('status', $income->number.' has been saved as a draft.');
    }

    public function edit(Request $request, Income $income): View
    {
        $this->authorize('update', $income);

        return view('incomes.edit', ['income' => $income, ...$this->formOptions($request->user())]);
    }

    public function update(IncomeRequest $request, Income $income): RedirectResponse
    {
        $this->authorize('update', $income);
        $data = $request->validated();
        $this->assertReachable($data, $request->user());

        try {
            $this->incomes->update($income, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['contract_id' => $e->getMessage()]);
        }

        return redirect()->route('incomes.show', $income)->with('status', $income->number.' has been updated.');
    }

    public function destroy(Request $request, Income $income): RedirectResponse
    {
        $this->authorize('delete', $income);
        $this->recordDeletion($income, $request->user(), 'income');
        $income->delete();

        return redirect()->route('incomes.index')->with('status', 'The draft income has been deleted.');
    }

    public function issue(Request $request, Income $income): RedirectResponse
    {
        $this->authorize('issue', $income);

        try {
            $this->incomes->issue($income, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $income->number.' has been issued.');
    }

    public function cancel(Request $request, Income $income): RedirectResponse
    {
        $this->authorize('manage', $income);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        try {
            $this->incomes->cancel($income, $request->user(), $reason);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $income->number.' has been cancelled.');
    }

    /** @param array<string,mixed> $data */
    private function assertReachable(array $data, User $user): void
    {
        $this->assertPlacementIsReachable($data);

        if (($client = $data['client_id'] ?? null) !== null
            && ! Client::visibleTo($user, 'client.view')->whereKey($client)->exists()) {
            abort(403);
        }

        if (($contract = $data['contract_id'] ?? null) !== null
            && ! Contract::visibleTo($user, 'contract.view')->whereKey($contract)->exists()) {
            abort(403);
        }
    }

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            ...$this->placementOptions($user),
            'categories' => $this->lists->for('income.categories', Income::DEFAULT_CATEGORIES),
            'clients' => $user->hasPermission('client.view')
                ? Client::visibleTo($user, 'client.view')->orderBy('name')->limit(500)->pluck('name', 'id')->all()
                : [],
            'contracts' => $user->hasPermission('contract.view')
                ? Contract::visibleTo($user, 'contract.view')->where('direction', 'client')->orderByDesc('id')->limit(500)->get()
                    ->mapWithKeys(fn (Contract $contract) => [$contract->id => $contract->displayName()])->all()
                : [],
            'taxCodes' => TaxCode::query()->usableFor('sales')->orderBy('code')->get()
                ->mapWithKeys(fn (TaxCode $code) => [$code->id => $code->displayName()])->all(),
        ];
    }
}
