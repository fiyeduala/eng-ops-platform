<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Report\ReportExporter;
use App\Engines\Report\ReportRunner;
use App\Models\KpiDefinition;
use App\Models\ReportDefinition;
use App\Models\SavedFilter;
use App\Services\ReportDefinitionService;
use App\Support\ReportCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * The report builder — what this company asks of its own records (§73, docs/11 §1).
 *
 * THE ROWS ON THIS PAGE BELONG TO WHOEVER OPENED IT. Not fetched once and shown to everybody:
 * listed, now, through this viewer's own visibility, which is why two people can open the same
 * report and correctly disagree about how many rows it has (§61). Nothing here is cached for the
 * same reason nothing is cached in the KPI controller — a stored result belongs to the person it
 * was built for, and serving it to the next reader hands over exactly the reach that visibility
 * was protecting.
 *
 * SEEING A REPORT IS NOT SEEING ITS ROWS, and this is the screen where that has to be true rather
 * than merely documented. Somebody holding `report.view` and no register permissions at all opens
 * the company's incident report and sees the question, the columns, and an empty table. The
 * alternative — hiding the report from them — would both mislead the department head whose three
 * visible incidents do exist and imply that the register permission is what fills the table.
 *
 * AD-HOC FILTERS GO THROUGH THE SAME COMPILER AS STORED ONES. The run screen lets somebody narrow a
 * report before exporting it, and those conditions arrive from a query string — so they are the
 * least trustworthy input in the module and get the identical whitelist check, in the identical
 * code path, rather than a second more careful one beside it (§84).
 *
 * EXPORTING IS ITS OWN PERMISSION AND ITS OWN AUDIT ENTRY (§59). ReportDefinitionPolicy argues why
 * a file is different from a screen; ReportExporter records what left, with its filters and row
 * count.
 */
class ReportDefinitionController extends Controller
{
    public function __construct(
        private readonly ReportDefinitionService $service,
        private readonly ReportRunner $runner,
        private readonly ReportExporter $exporter,
    ) {}

    /**
     * Every report this company has built.
     *
     * Listed through `visibleTo` rather than a bare query. Which questions a company asks of itself
     * is itself information — a report called "contractors under investigation" discloses that there
     * is an investigation — so §61 covers the list as well as the rows.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ReportDefinition::class);

        $user = $request->user();

        $reports = ReportDefinition::query()
            ->visibleTo($user, 'report.view')
            ->with('author')
            ->ordered()
            ->get();

        return view('configuration.reports.index', [
            'reports' => $reports,
            'entities' => ReportCatalogue::entities(),
            'canCreate' => $user->can('create', ReportDefinition::class),
            'canEdit' => $user->can('create', ReportDefinition::class),
        ]);
    }

    /**
     * One report, run for the person asking.
     *
     * `$this->authorize('view', ...)` before anything else, and the runner checks the tenant again
     * on its own account. Two checks over one route is not redundancy worth removing: the policy
     * answers "may this person open reports", the runner answers "is this report even this
     * company's" (§75), and a future caller of the runner that is not this controller will still
     * get the second one.
     */
    public function show(Request $request, ReportDefinition $report): View
    {
        $this->authorize('view', $report);

        $user = $request->user();
        $adHoc = $this->adHocFilters($request, $report);

        $result = $this->runner->run($report, $user, $adHoc);

        // The saved views for THIS REGISTER, not this report (§54). "Open in Civil" is worth having
        // on every report over documents, and tying it to one definition would make it three
        // bookmarks to maintain and two to forget. `readableBy` decides which names appear; it
        // decides nothing about the rows, which the runner above has already settled.
        $savedViews = SavedFilter::query()
            ->readableBy($user)
            ->forEntity($report->entity_type)
            ->with('owner:id,name')
            ->ordered()
            ->get();

        return view('configuration.reports.show', [
            'report' => $report,
            'result' => $result,
            'adHoc' => $adHoc,
            'savedViews' => $savedViews,
            'visibilities' => SavedFilter::VISIBILITIES,
            'canShareWidely' => $user->can('create', ReportDefinition::class),
            'filterFields' => ReportCatalogue::filterFieldsFor($report->entity_type),
            'operators' => KpiDefinition::FILTER_OPERATORS,
            'operatorsWithoutValue' => KpiDefinition::OPERATORS_WITHOUT_VALUE,
            'entities' => ReportCatalogue::entities(),
            'canExport' => $user->can('export', $report),
            'canEdit' => $user->can('update', $report),
        ]);
    }

    /**
     * The same rows, as a file.
     *
     * RUN AGAIN RATHER THAN CARRIED OVER FROM THE SCREEN. The export re-runs the report through the
     * exporting person's own visibility at the moment they click, so a person whose access was
     * withdrawn between opening the page and pressing Export gets the narrower answer — not the
     * one that was on their screen. Passing the rendered rows through the session would have been
     * less work and would have made a stale view exportable.
     */
    public function export(Request $request, ReportDefinition $report, string $format): Response
    {
        $this->authorize('export', $report);

        if (! array_key_exists($format, ReportDefinition::FORMATS)) {
            abort(404);
        }

        $user = $request->user();
        $adHoc = $this->adHocFilters($request, $report);

        $result = $this->runner->run($report, $user, $adHoc);

        if (! $result->available) {
            return redirect()
                ->route('configuration.reports.show', $report)
                ->withErrors(['export' => $result->reason ?? 'This report could not be run.']);
        }

        return $format === 'csv'
            ? $this->exporter->csv($report, $result, $user, $adHoc)
            : $this->exporter->pdf($report, $result, $user, $adHoc);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', ReportDefinition::class);

        return view('configuration.reports.form', $this->formData(new ReportDefinition));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ReportDefinition::class);

        try {
            $report = $this->service->create($this->payload($request), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('configuration.reports.show', $report)
            ->with('status', '“'.$report->name.'” is ready. These are the rows you can see.');
    }

    public function edit(Request $request, ReportDefinition $report): View
    {
        $this->authorize('update', $report);

        return view('configuration.reports.form', $this->formData($report));
    }

    public function update(Request $request, ReportDefinition $report): RedirectResponse
    {
        $this->authorize('update', $report);

        try {
            $this->service->update($report, $this->payload($request), $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()
            ->route('configuration.reports.show', $report)
            ->with('status', '“'.$report->name.'” has been changed. The audit trail records what moved.');
    }

    /**
     * Take a report off the list without losing the question.
     *
     * Next to the delete button, and almost always the right one. A report sent to a client last
     * quarter is only accountable while the definition behind it still exists — deleting it makes
     * the figures in that document impossible to reproduce or defend.
     */
    public function toggle(Request $request, ReportDefinition $report): RedirectResponse
    {
        $this->authorize('update', $report);

        $this->service->setActive($report, ! $report->is_active, $request->user());

        return back()->with('status', $report->is_active
            ? '“'.$report->name.'” is back on the list.'
            : '“'.$report->name.'” has been switched off. The definition is kept.');
    }

    public function destroy(Request $request, ReportDefinition $report): RedirectResponse
    {
        $this->authorize('delete', $report);

        $name = $report->name;

        $this->service->delete($report, $request->user());

        return redirect()
            ->route('configuration.reports.index')
            ->with('status', '“'.$name.'” has been deleted.');
    }

    // ================================================================== input

    /**
     * Conditions typed on the run screen.
     *
     * READ FROM THE QUERY STRING AND THEREFORE TRUSTED WITH NOTHING. Anything malformed is dropped
     * here and anything unrecognised is refused by the compiler downstream; what this method does
     * NOT do is silently discard a condition it does not understand while running the report
     * anyway, because a filter that vanishes turns "incidents in Civil" into "all incidents" and
     * the answer looks just as authoritative.
     *
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    private function adHocFilters(Request $request, ReportDefinition $report): array
    {
        $filters = [];

        foreach ((array) $request->input('filters', []) as $filter) {
            if (! is_array($filter)) {
                continue;
            }

            $field = (string) ($filter['field'] ?? '');
            $operator = (string) ($filter['operator'] ?? '');

            if ($field === '' || ! array_key_exists($operator, KpiDefinition::FILTER_OPERATORS)) {
                continue;
            }

            // Checked here as well as in the compiler so the screen can show the condition back to
            // the person who typed it. A field the register does not offer never becomes a filter.
            if (! ReportCatalogue::offersFilter($report->entity_type, $field)) {
                continue;
            }

            $value = $filter['value'] ?? null;

            if (! in_array($operator, KpiDefinition::OPERATORS_WITHOUT_VALUE, true) && blank($value)) {
                continue;
            }

            $filters[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => is_scalar($value) || is_array($value) ? $value : null,
            ];
        }

        return array_slice($filters, 0, ReportDefinition::MAX_FILTERS);
    }

    /**
     * What the builder may say about a report.
     *
     * An explicit list rather than `$request->all()`: `organization_id` and `created_by` are the
     * actor's and nothing else's (§74), and a payload key nobody put on the form has no business
     * reaching `fill()` (§84). The service checks every value again against ReportCatalogue — this
     * decides the vocabulary, not the truth.
     *
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'name' => (string) $request->input('name', ''),
            'description' => $request->input('description'),
            'entity_type' => (string) $request->input('entity_type', ''),
            'columns' => (array) $request->input('columns', []),
            'filters' => (array) $request->input('filters', []),
            'group_field' => (string) $request->input('group_field', ''),
            'sort_field' => (string) $request->input('sort_field', ''),
            'sort_direction' => (string) $request->input('sort_direction', 'desc'),
            'row_limit' => $request->input('row_limit'),
            'sort' => (int) $request->input('sort', 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * Everything the builder needs to offer, including the whole field map.
     *
     * Handed over as data for the reason the KPI builder gives: it is the same for every company on
     * the installation, it changes only on upgrade, and an endpoint serving it would be one more
     * surface to get authorization wrong on for no benefit anybody would notice.
     *
     * FOUR LISTS PER REGISTER, NOT ONE. Columns, filters, groupings and sorts are separate here
     * because they are separate in ReportCatalogue, and collapsing them in the UI is how a builder
     * ends up offering a free-text filter — the exact thing the catalogue refuses to return.
     *
     * @return array<string,mixed>
     */
    private function formData(ReportDefinition $report): array
    {
        $fields = [];

        foreach (array_keys(ReportCatalogue::entities()) as $entityType) {
            $fields[$entityType] = [
                'columns' => ReportCatalogue::columnsFor($entityType),
                'filters' => ReportCatalogue::filterFieldsFor($entityType),
                'groups' => ReportCatalogue::groupFieldsFor($entityType),
                'sorts' => ReportCatalogue::sortFieldsFor($entityType),
            ];
        }

        return [
            'report' => $report,
            'fields' => $fields,
            'entities' => ReportCatalogue::groupedEntities(),
            'operators' => KpiDefinition::FILTER_OPERATORS,
            'operatorsWithoutValue' => KpiDefinition::OPERATORS_WITHOUT_VALUE,
            'directions' => ReportDefinition::DIRECTIONS,
            'maxFilters' => ReportDefinition::MAX_FILTERS,
            'maxColumns' => ReportDefinition::MAX_COLUMNS,
            'maxRows' => ReportDefinition::MAX_ROWS,
        ];
    }
}
