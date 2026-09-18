<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Http\Requests\PayComponentRequest;
use App\Models\PayComponent;
use App\Services\PayStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Setting up how the company pays people.
 *
 * THIS IS WHERE §47 IS ACTUALLY DISCHARGED. Meridian's administrator types in PAYE, pension and NHF;
 * Kestrel's types in PAYE, National Insurance and a student loan deduction. Neither appears anywhere
 * in the code, and the calculator that applies them cannot tell the two companies apart. If this
 * screen were ever replaced by a "choose your country" dropdown, the platform would have taken on
 * responsibility for tax law in every jurisdiction it is ever sold into.
 *
 * NO FIGURES ABOUT PEOPLE LIVE HERE. A component describes a rule, not a salary, which is why the
 * page is reachable with `payroll.edit` and not `payroll.view_confidential`. An administrator
 * setting up tax tables should not thereby be able to read the payroll.
 */
class PayComponentController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly PayStructureService $structure,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PayComponent::class);

        return view('pay-components.index', [
            // In calculation order, because that order IS the company's policy — whether pension is
            // deducted before or after tax is expressed by nothing else. A list sorted by name would
            // hide the one thing an administrator most needs to check.
            'components' => $this->compiler
                ->apply(PayComponent::query(), $request->user(), 'payroll.edit')
                ->inCalculationOrder()
                ->withCount('bands')
                ->get(),
            'kinds' => PayComponent::KINDS,
            'canCreate' => $request->user()->can('create', PayComponent::class),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', PayComponent::class);

        // The view variable is `payComponent`, never `component`: Blade binds `$component` to the
        // component instance inside every component slot, so a form field reading `$component->code`
        // inside an <x-field> gets an AnonymousComponent and fatals.
        return view('pay-components.create', [
            'payComponent' => new PayComponent([
                'kind' => 'earning',
                'method' => 'fixed',
                'basis' => 'none',
                'is_active' => true,
                'sequence' => 100,
            ]),
            ...$this->formOptions(),
        ]);
    }

    public function store(PayComponentRequest $request): RedirectResponse
    {
        $this->authorize('create', PayComponent::class);

        $component = $this->structure->createComponent($request->validated(), $request->user());

        return redirect()->route('pay-components.index')
            ->with('status', $component->code.' has been added to the pay structure.');
    }

    public function edit(PayComponent $payComponent): View
    {
        $this->authorize('update', $payComponent);
        $payComponent->load('bands');

        return view('pay-components.edit', [
            'payComponent' => $payComponent,
            ...$this->formOptions(),
        ]);
    }

    public function update(PayComponentRequest $request, PayComponent $payComponent): RedirectResponse
    {
        $this->authorize('update', $payComponent);

        $this->structure->updateComponent($payComponent, $request->validated(), $request->user());

        return redirect()->route('pay-components.index')
            ->with('status', $payComponent->code.' has been updated. Runs already approved keep the figures they were calculated with.');
    }

    public function destroy(PayComponent $payComponent): RedirectResponse
    {
        // The policy refuses this outright for a component that has been calculated against, so the
        // only thing reaching here is a row typed by mistake and never used.
        $this->authorize('delete', $payComponent);
        $payComponent->delete();

        return redirect()->route('pay-components.index')->with('status', 'The component has been removed.');
    }

    /** @return array<string,mixed> */
    private function formOptions(): array
    {
        return [
            'kinds' => PayComponent::KINDS,
            'methods' => PayComponent::METHODS,
            'bases' => PayComponent::BASES,
        ];
    }
}
