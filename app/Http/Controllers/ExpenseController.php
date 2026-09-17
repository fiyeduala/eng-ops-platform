<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\ExpenseRequest;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ExpenseService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Expense claims: drafted by the person who spent the money, approved by somebody else, and on
 * approval posted to the project as a cost.
 *
 * Every foreign key on the form is re-checked here against what THIS person can reach (§84): the
 * form request proves the vendor or cost center belongs to the company, only this controller can
 * prove the claimant may file against it.
 */
class ExpenseController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly ExpenseService $expenses,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'expense';
    }

    protected function modelClass(): string
    {
        return Expense::class;
    }

    protected function routePrefix(): string
    {
        return 'expenses';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Expense::class);
        $user = $request->user();
        $query = $this->scopedQuery($user)->with(['status', 'project', 'claimant', 'vendor']);
        $this->applyCommonFilters($query, $request);

        if ($request->query('view') === 'mine') {
            $query->where('claimant_id', $user->id);
        }

        return view('expenses.index', [
            'expenses' => $query->orderByDesc('expense_date')->orderByDesc('id')->paginate(40)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('expense'),
            'projects' => $this->projectOptions($user),
            'filters' => $this->commonFilters($request, 'all'),
            'canCreate' => $user->can('create', Expense::class),
        ]);
    }

    public function show(Request $request, Expense $expense): View
    {
        $this->authorize('view', $expense);
        $user = $request->user();
        $expense->load(['status', 'project', 'orgUnit', 'costCenter', 'claimant', 'vendor', 'taxCode', 'approver', 'projectCost', 'creator']);

        return view('expenses.show', [
            'expense' => $expense,
            'payments' => $expense->payments()->visibleTo($user, 'payment.view')->with('status')->latest('id')->get(),
            'timeline' => $this->activity->timelineFor($expense)->limit(25)->get(),
            'canEdit' => $user->can('update', $expense),
            'canDelete' => $user->can('delete', $expense),
            'canSubmit' => $user->can('submit', $expense),
            'canApprove' => $user->can('approve', $expense),
            'canReject' => $user->can('reject', $expense),
            'canPay' => $expense->isPayable() && $user->can('create', Payment::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Expense::class);

        return view('expenses.create', [
            'expense' => new Expense([
                'expense_date' => now()->toDateString(),
                'claimant_id' => $request->user()->id,
                'confidentiality' => Confidentiality::Internal->value,
            ]),
            ...$this->formOptions($request->user()),
        ]);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $this->authorize('create', Expense::class);
        $data = $this->reachable($request->validated(), $request->user());

        try {
            $expense = $this->expenses->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('expenses.show', $expense)->with('status', $expense->number.' has been saved as a draft.');
    }

    public function edit(Request $request, Expense $expense): View
    {
        $this->authorize('update', $expense);

        return view('expenses.edit', ['expense' => $expense, ...$this->formOptions($request->user())]);
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $this->authorize('update', $expense);
        $data = $this->reachable($request->validated(), $request->user(), $expense);

        try {
            $this->expenses->update($expense, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('expenses.show', $expense)->with('status', $expense->number.' has been updated.');
    }

    public function destroy(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('delete', $expense);
        $this->recordDeletion($expense, $request->user(), 'expense');
        $expense->delete();

        return redirect()->route('expenses.index')->with('status', 'The draft expense has been deleted.');
    }

    public function submit(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('submit', $expense);

        return $this->attempt(fn () => $this->expenses->submit($expense, $request->user()), 'The expense has been submitted for approval.');
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('approve', $expense);
        $comment = $request->validate(['comment' => ['nullable', 'string', 'max:1000']])['comment'] ?? null;

        return $this->attempt(fn () => $this->expenses->approve($expense, $request->user(), $comment), 'The expense has been approved and posted to its costs.');
    }

    public function returnForAmendment(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('approve', $expense);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => $this->expenses->returnForAmendment($expense, $request->user(), $reason), 'The expense has been returned to the claimant.');
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        $this->authorize('reject', $expense);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return $this->attempt(fn () => $this->expenses->reject($expense, $request->user(), $reason), 'The expense has been rejected.');
    }

    private function attempt(callable $action, string $message): RedirectResponse
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', $message);
    }

    /**
     * Placement, vendor, cost center and claimant — each one somewhere this person may go.
     *
     * Claiming on somebody else's behalf is `manage`. Without that, the claimant is always the
     * person filling in the form (or, on edit, whoever it already was), whatever the form says.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function reachable(array $data, User $user, ?Expense $existing = null): array
    {
        $this->assertPlacementIsReachable($data);

        if (($vendor = $data['vendor_id'] ?? null) !== null
            && ! Vendor::visibleTo($user, 'vendor.view')->whereKey($vendor)->exists()) {
            abort(403);
        }

        if (($center = $data['cost_center_id'] ?? null) !== null
            && ! CostCenter::visibleTo($user, 'budget.view')->whereKey($center)->exists()) {
            abort(403);
        }

        $claimant = $data['claimant_id'] ?? null;
        $fallback = $existing?->claimant_id ?? $user->id;

        if ($claimant === null || (int) $claimant === (int) $fallback) {
            $data['claimant_id'] = $fallback;
        } elseif (! $user->hasPermission('expense.manage')
            || ! $this->compiler->apply(User::query(), $user, 'employee.view')->whereKey($claimant)->exists()) {
            abort(403);
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function formOptions(User $user): array
    {
        return [
            ...$this->placementOptions($user),
            'categories' => $this->lists->for('expense.categories', Expense::DEFAULT_CATEGORIES),
            'vendors' => $user->hasPermission('vendor.view')
                ? Vendor::visibleTo($user, 'vendor.view')->orderBy('name')->limit(500)->pluck('name', 'id')->all()
                : [],
            'taxCodes' => TaxCode::query()->usableFor('purchases')->orderBy('code')->get()
                ->mapWithKeys(fn (TaxCode $code) => [$code->id => $code->displayName()])->all(),
            'costCenters' => $user->hasPermission('budget.view')
                ? CostCenter::visibleTo($user, 'budget.view')->where('is_active', true)->orderBy('code')->get()
                    ->mapWithKeys(fn ($center) => [$center->id => $center->displayName()])->all()
                : [],
            'canChooseClaimant' => $user->hasPermission('expense.manage'),
        ];
    }
}
