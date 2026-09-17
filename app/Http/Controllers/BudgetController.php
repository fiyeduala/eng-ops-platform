<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\BudgetRequest;
use App\Models\Budget;
use App\Models\CostCenter;
use App\Models\User;
use App\Services\BudgetService;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BudgetController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly BudgetService $budgets,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'budget';
    }

    protected function modelClass(): string
    {
        return Budget::class;
    }

    protected function routePrefix(): string
    {
        return 'budgets';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Budget::class);
        $query = $this->scopedQuery($request->user())->with(['status', 'project', 'orgUnit', 'costCenter']);
        $this->applyCommonFilters($query, $request);
        $query->fiscalYear($request->query('year'));

        return view('budgets.index', [
            'budgets' => $query->orderByDesc('fiscal_year')->orderBy('number')->paginate(40)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('budget'),
            'projects' => $this->projectOptions($request->user()),
            'filters' => [...$this->commonFilters($request, 'all'), 'year' => $request->query('year')],
            'canCreate' => $request->user()->can('create', Budget::class),
        ]);
    }

    public function show(Request $request, Budget $budget): View
    {
        $this->authorize('view', $budget);
        $budget->load(['status', 'project', 'orgUnit', 'costCenter', 'owner', 'items', 'creator']);
        $actualByCategory = $budget->actualCosts()->selectRaw('cost_type, SUM(base_amount) total')
            ->groupBy('cost_type')->pluck('total', 'cost_type');
        $actual = $actualByCategory->sum(fn ($amount) => (float) $amount);

        return view('budgets.show', [
            'budget' => $budget,
            'actualByCategory' => $actualByCategory,
            'actual' => $actual,
            'timeline' => $this->activity->timelineFor($budget)->limit(25)->get(),
            'canEdit' => $request->user()->can('update', $budget),
            'canDelete' => $request->user()->can('delete', $budget),
            'canApprove' => $request->user()->can('approve', $budget),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Budget::class);

        return view('budgets.create', [
            'budget' => new Budget([
                'fiscal_year' => now()->year,
                'starts_on' => now()->startOfYear(),
                'ends_on' => now()->endOfYear(),
                'confidentiality' => Confidentiality::Internal->value,
            ]),
            ...$this->formOptions($request),
        ]);
    }

    public function store(BudgetRequest $request): RedirectResponse
    {
        $this->authorize('create', Budget::class);
        $data = $request->budgetAttributes();
        $this->assertPlacementIsReachable($data);
        $this->assertCostCenterReachable($data, $request->user());
        try {
            $budget = $this->budgets->create($data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['cost_center_id' => $e->getMessage()]);
        }

        return redirect()->route('budgets.show', $budget)->with('status', $budget->displayName().' has been created.');
    }

    public function edit(Request $request, Budget $budget): View
    {
        $this->authorize('update', $budget);
        $budget->load('items');

        return view('budgets.edit', ['budget' => $budget, ...$this->formOptions($request)]);
    }

    public function update(BudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);
        $data = $request->budgetAttributes();
        $this->assertPlacementIsReachable($data);
        $this->assertCostCenterReachable($data, $request->user());
        try {
            $this->budgets->update($budget, $data, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['cost_center_id' => $e->getMessage()]);
        }

        return redirect()->route('budgets.show', $budget)->with('status', $budget->displayName().' has been updated.');
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);
        $this->recordDeletion($budget, request()->user(), 'budget');
        $budget->delete();

        return redirect()->route('budgets.index')->with('status', 'The draft budget has been deleted.');
    }

    public function submit(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);
        try {
            $this->budgets->submit($budget, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The budget has been submitted for approval.');
    }

    public function approve(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('approve', $budget);
        try {
            $this->budgets->approve($budget, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The budget has been approved.');
    }

    public function returnForAmendment(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('approve', $budget);
        try {
            $this->budgets->returnForAmendment($budget, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The budget has been returned for amendment.');
    }

    public function reject(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('approve', $budget);
        try {
            $this->budgets->reject($budget, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The budget has been rejected.');
    }

    public function close(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('manage', $budget);
        try {
            $this->budgets->close($budget, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return back()->with('status', 'The budget has been closed.');
    }

    private function formOptions(Request $request): array
    {
        $user = $request->user();

        return [
            ...$this->placementOptions($user),
            'costCenters' => CostCenter::visibleTo($user, 'budget.view')->where('is_active', true)
                ->orderBy('code')->get()->mapWithKeys(fn ($center) => [$center->id => $center->displayName()])->all(),
            'owners' => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    private function assertCostCenterReachable(array $data, User $user): void
    {
        if (($id = $data['cost_center_id'] ?? null) !== null
            && ! CostCenter::visibleTo($user, 'budget.view')->whereKey($id)->exists()) {
            abort(403);
        }
    }
}
