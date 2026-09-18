<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\PayrollRunRequest;
use App\Models\PayrollRun;
use App\Services\PayrollService;
use App\Support\OptionList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The payroll register: open a period, calculate it, send it, and never touch it again.
 *
 * THERE IS NO APPROVE ACTION ON THIS CONTROLLER AND NO APPROVE ROUTE. Search the file: `submit` is
 * the last thing that happens here. The decision is taken at `approvals.decide`, the same URL that
 * decides a drawing, and it arrives back through the service's `afterApproved()` hook. That is Phase
 * 6's exit criterion, and payroll is the test it most needed to pass.
 *
 * CALCULATE IS A POST, NOT A GET. It writes a payroll's worth of rows and replaces whatever was
 * there before; a link somebody could follow, prefetch or bookmark would recalculate the run.
 */
class PayrollRunController extends RegisterController
{
    public function __construct(
        ScopeCompiler $compiler,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        NumberGenerator $numbers,
        OptionList $lists,
        private readonly PayrollService $payroll,
    ) {
        parent::__construct($compiler, $statuses, $activity, $numbers, $lists);
    }

    protected function entityType(): string
    {
        return 'payroll_run';
    }

    protected function modelClass(): string
    {
        return PayrollRun::class;
    }

    protected function routePrefix(): string
    {
        return 'payroll-runs';
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PayrollRun::class);
        $user = $request->user();

        $query = $this->scopedQuery($user, 'payroll.view')->with(['status', 'corrects']);
        $this->applyCommonFilters($query, $request);
        $filters = $this->commonFilters($request, 'open');

        if ($filters['view'] === 'open') {
            $query->whereHas('status', fn ($q) => $q->whereIn('key', ['draft', 'calculated', 'submitted']));
        }

        return view('payroll-runs.index', [
            'runs' => $query->orderByDesc('period_start')->orderByDesc('id')->paginate(25)->withQueryString(),
            'statuses' => $this->statuses->optionsFor('payroll_run'),
            'filters' => $filters,
            'canCreate' => $user->can('create', PayrollRun::class),
        ]);
    }

    public function show(Request $request, PayrollRun $payrollRun): View
    {
        $this->authorize('view', $payrollRun);
        $user = $request->user();
        $payrollRun->load(['status', 'corrects', 'corrections.status']);

        $instance = $payrollRun->workflowInstances()
            ->with(['stageInstances.stage', 'stageInstances.approvals.user'])
            ->latest('id')
            ->first();

        return view('payroll-runs.show', [
            'run' => $payrollRun,
            'instance' => $instance,
            // Paginated rather than loaded whole. A run is every employee in the company, and a show
            // page that renders two thousand payslips is a page nobody can open (§89).
            'payslips' => $payrollRun->payslips()
                ->with('employee')
                ->orderBy('number')
                ->paginate(50)
                ->withQueryString(),
            'timeline' => $this->activity->timelineFor($payrollRun)->limit(25)->get(),
            'canEdit' => $user->can('update', $payrollRun),
            'canDelete' => $user->can('delete', $payrollRun),
            'canCalculate' => $user->can('calculate', $payrollRun),
            'canSubmit' => $user->can('submit', $payrollRun),
            'canCorrect' => $user->can('correct', $payrollRun),
            'canCancel' => $user->can('cancel', $payrollRun),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PayrollRun::class);

        $start = now()->startOfMonth();

        return view('payroll-runs.create', [
            'run' => new PayrollRun([
                'name' => $start->format('F Y').' payroll',
                'period_start' => $start->toDateString(),
                'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'pay_date' => $start->copy()->endOfMonth()->toDateString(),
                'frequency' => 'monthly',
                'currency' => $request->user()->organization->currency,
            ]),
            ...$this->formOptions(),
        ]);
    }

    public function store(PayrollRunRequest $request): RedirectResponse
    {
        $this->authorize('create', PayrollRun::class);

        $run = $this->payroll->create($request->validated(), $request->user());

        return redirect()->route('payroll-runs.show', $run)
            ->with('status', $run->number.' has been opened. Calculate it when you are ready.');
    }

    public function edit(PayrollRun $payrollRun): View
    {
        $this->authorize('update', $payrollRun);

        return view('payroll-runs.edit', [
            'run' => $payrollRun,
            ...$this->formOptions(),
        ]);
    }

    public function update(PayrollRunRequest $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorize('update', $payrollRun);

        $this->payroll->update($payrollRun, $request->validated(), $request->user());

        return redirect()->route('payroll-runs.show', $payrollRun)
            ->with('status', $payrollRun->number.' has been updated. Calculate it again to pick the change up.');
    }

    public function destroy(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorize('delete', $payrollRun);
        $this->recordDeletion($payrollRun, $request->user(), 'payroll run');
        $payrollRun->delete();

        return redirect()->route('payroll-runs.index')->with('status', 'The run has been deleted.');
    }

    // ------------------------------------------------------------------ the three acts

    public function calculate(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorize('calculate', $payrollRun);

        try {
            $this->payroll->calculate($payrollRun, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        $skipped = $payrollRun->payslips()->whereNotNull('skipped_reason')->count();

        return back()->with('status', $payrollRun->number.' has been calculated — '
            .$payrollRun->payslip_count.' '.str('payslip')->plural($payrollRun->payslip_count)
            // Surfaced on the way out rather than left for somebody to notice. A skipped payslip is
            // a person who will not be paid, and pay day is the wrong time to find out.
            .($skipped > 0 ? ', '.$skipped.' of which could not be paid. Check the register.' : '.'));
    }

    public function submit(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorize('submit', $payrollRun);

        try {
            $this->payroll->submit($payrollRun, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('status', $payrollRun->number.' has been sent for approval.');
    }

    /**
     * Open a new run against one that is already locked.
     *
     * ONLY A NAME AND A NOTE ARE ACCEPTED. The period, frequency and currency are read off the run
     * being corrected, not off the form — a correction that could be posted for a different period,
     * or in a different currency, is not a correction (§84). Nothing here is a hidden field, because
     * a hidden field is a field somebody can change.
     */
    public function correct(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorize('correct', $payrollRun);

        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $run = $this->payroll->openCorrection($payrollRun, $attributes, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['run' => $e->getMessage()]);
        }

        return redirect()->route('payroll-runs.show', $run)
            ->with('status', $run->number.' has been opened to correct '.$payrollRun->number.'.');
    }

    public function cancel(Request $request, PayrollRun $payrollRun): RedirectResponse
    {
        $this->authorize('cancel', $payrollRun);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        $this->payroll->cancel($payrollRun, $request->user(), $reason);

        return back()->with('status', 'The run has been cancelled.');
    }

    /** @return array<string,mixed> */
    private function formOptions(): array
    {
        return ['frequencies' => PayrollRun::FREQUENCIES];
    }
}
