<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Kpi\KpiEvaluator;
use App\Models\KpiDefinition;
use App\Services\KpiDefinitionService;
use App\Support\KpiCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The measure builder — what this company counts, said in the company's own words (§56, §73).
 *
 * THE NUMBERS ON THIS PAGE ARE COUNTED FOR WHOEVER OPENED IT. Not fetched, not cached, not read off
 * the definition: counted, now, through this viewer's own visibility, which is why two people can
 * open the same list and correctly disagree about every figure on it (§61). That is a feature and
 * the reason nothing here is memoised — a cached total belongs to whoever it was counted for, and
 * handing it to the next reader gives away exactly the reach that visibility was protecting.
 *
 * READING THE LIST AND READING THE NUMBERS ARE DIFFERENT PERMISSIONS IN PRACTICE, even though both
 * sit behind `kpi.view`. Somebody may see that "open incidents" is defined as a count of incidents
 * whose status is not closed, and still see a smaller number against it than the safety manager
 * does, because the definition is configuration and the figure is data. KpiDefinitionPolicy says
 * this at more length.
 *
 * EDITING A MEASURE BREAKS NOTHING AND CHANGES EVERYTHING, which is why `manage` is its own
 * permission and every save is audited. No record moves and no screen errors when a filter is
 * widened; what happens is that a figure a board has been reading for six quarters quietly starts
 * meaning something else under the same heading.
 */
class KpiDefinitionController extends Controller
{
    public function __construct(
        private readonly KpiDefinitionService $service,
        private readonly KpiEvaluator $evaluator,
    ) {}

    /**
     * Every measure this company has defined, each with the figure as this viewer sees it.
     *
     * Definitions are listed through `visibleTo` rather than a bare query, because which measures a
     * company watches is itself a statement about the company: a "days since last lost-time injury"
     * tile tells somebody there have been injuries. §61 covers the list as well as the totals.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', KpiDefinition::class);

        $user = $request->user();

        $definitions = KpiDefinition::query()
            ->visibleTo($user, 'kpi.view')
            ->ordered()
            ->get();

        $rows = [];

        foreach ($definitions as $definition) {
            $rows[] = [
                'kpi' => $definition,
                // No scope id, so a measure defined "per department" is shown here across
                // everything this person can see. The screen says so rather than leaving a figure
                // that looks like one department's and is not.
                'result' => $this->evaluator->evaluate($definition, $user),
            ];
        }

        return view('configuration.kpis.index', [
            'rows' => $rows,
            'entities' => KpiCatalogue::countableEntities(),
            'scopes' => KpiDefinition::SCOPES,
            'aggregates' => KpiDefinition::AGGREGATES,
            'canManage' => $user->can('create', KpiDefinition::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', KpiDefinition::class);

        return view('configuration.kpis.form', $this->formData(new KpiDefinition));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', KpiDefinition::class);

        try {
            $kpi = $this->service->create($this->payload($request), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('configuration.kpis.index')
            ->with('status', '“'.$kpi->name.'” is now being measured.');
    }

    public function edit(Request $request, KpiDefinition $kpi): View
    {
        $this->authorize('update', $kpi);

        return view('configuration.kpis.form', $this->formData($kpi));
    }

    public function update(Request $request, KpiDefinition $kpi): RedirectResponse
    {
        $this->authorize('update', $kpi);

        try {
            $this->service->update($kpi, $this->payload($request), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('configuration.kpis.index')
            ->with('status', 'How “'.$kpi->name.'” is measured has been changed. The audit trail records what moved.');
    }

    /**
     * Take a measure off the dashboard without losing the definition.
     *
     * Offered prominently, and next to the delete button on purpose, because this is almost always
     * the right one. A figure that has been quoted in a board pack is only reproducible while the
     * definition behind it still exists; deleting it makes last quarter's number unarguable in the
     * worst sense — nobody can say what it counted.
     */
    public function toggle(Request $request, KpiDefinition $kpi): RedirectResponse
    {
        $this->authorize('update', $kpi);

        $this->service->setActive($kpi, ! $kpi->is_active, $request->user());

        return back()->with('status', $kpi->is_active
            ? '“'.$kpi->name.'” is being measured again.'
            : '“'.$kpi->name.'” has been switched off. The definition is kept.');
    }

    public function destroy(Request $request, KpiDefinition $kpi): RedirectResponse
    {
        $this->authorize('delete', $kpi);

        $name = $kpi->name;

        $this->service->delete($kpi, $request->user());

        return redirect()
            ->route('configuration.kpis.index')
            ->with('status', '“'.$name.'” has been deleted.');
    }

    /**
     * What the builder may say about a measure.
     *
     * An explicit list rather than `$request->all()`: `organization_id` is the actor's and nothing
     * else's (§74), and a payload key nobody put on the form has no business reaching `fill()`
     * (§84). The service checks every value in here again against KpiCatalogue — this decides the
     * vocabulary, not the truth.
     *
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'name' => (string) $request->input('name', ''),
            'description' => $request->input('description'),
            'entity_type' => (string) $request->input('entity_type', ''),
            'aggregate' => (string) $request->input('aggregate', 'count'),
            'measure_field' => (string) $request->input('measure_field', ''),
            'filters' => (array) $request->input('filters', []),
            'numerator_filters' => (array) $request->input('numerator_filters', []),
            'period' => (string) $request->input('period', 'all_time'),
            'date_field' => (string) $request->input('date_field', ''),
            'scope' => (string) $request->input('scope', 'organization'),
            'target' => $request->input('target'),
            'unit' => $request->input('unit'),
            'direction' => (string) $request->input('direction', 'higher_better'),
            'thresholds' => (array) $request->input('thresholds', []),
            'sort' => (int) $request->input('sort', 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * Everything the builder needs to offer, including the whole field map.
     *
     * The map is handed over as data — twenty-odd kilobytes of short strings — rather than fetched
     * per register over a second request. It is the same for every company on the installation, it
     * changes only when the platform is upgraded, and an endpoint serving it would be one more
     * surface to get authorization wrong on for no benefit anybody would notice.
     *
     * @return array<string,mixed>
     */
    private function formData(KpiDefinition $kpi): array
    {
        $fields = [];

        foreach (array_keys(KpiCatalogue::countableEntities()) as $entityType) {
            $fields[$entityType] = [
                'filters' => KpiCatalogue::filterFieldsFor($entityType),
                'measures' => KpiCatalogue::measureFieldsFor($entityType),
                'dates' => KpiCatalogue::dateFieldsFor($entityType),
                // Which altitudes this register can actually be cut at. Offering "per project" for
                // a register with no project column would produce a measure that saves, validates
                // and then renders as a permanently blank tile on every project page.
                'scopes' => $this->scopesFor($entityType),
            ];
        }

        return [
            'kpi' => $kpi,
            'fields' => $fields,
            'entities' => KpiCatalogue::groupedEntities(),
            'aggregates' => KpiDefinition::AGGREGATES,
            'needsMeasure' => KpiDefinition::AGGREGATES_NEEDING_MEASURE,
            'needsNumerator' => KpiDefinition::AGGREGATES_NEEDING_NUMERATOR,
            'operators' => KpiDefinition::FILTER_OPERATORS,
            'operatorsWithoutValue' => KpiDefinition::OPERATORS_WITHOUT_VALUE,
            'periods' => KpiDefinition::PERIODS,
            'scopes' => KpiDefinition::SCOPES,
            'directions' => KpiDefinition::DIRECTIONS,
            'maxFilters' => KpiDefinition::MAX_FILTERS,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function scopesFor(string $entityType): array
    {
        $offered = ['organization'];

        if (KpiCatalogue::orgUnitColumnFor($entityType) !== null) {
            $offered[] = 'department';
        }

        if (KpiCatalogue::projectColumnFor($entityType) !== null) {
            $offered[] = 'project';
        }

        if (KpiCatalogue::employeeColumnFor($entityType) !== null) {
            $offered[] = 'employee';
        }

        return $offered;
    }
}
