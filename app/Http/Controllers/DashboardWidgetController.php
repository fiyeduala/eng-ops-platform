<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DashboardWidget;
use App\Models\KpiDefinition;
use App\Models\ReportDefinition;
use App\Policies\DashboardWidgetPolicy;
use App\Services\DashboardWidgetService;
use App\Support\WidgetCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The board builder — what this company looks at every morning (§51, §52, §53, §73).
 *
 * WHAT THIS SCREEN SHOWS IS THE ARRANGEMENT, NOT THE BOARD. It lists tiles and says what each one
 * draws from; it shows no figures at all. A preview would have to be counted for SOMEBODY, and the
 * only honest somebody is the administrator standing at this screen — who is generally not who the
 * board is for, so the preview would be both misleading and, on the other reading, a request for a
 * "see it as the managing director would" mode. That mode is a way to read the managing director's
 * figures, so it does not exist (§61). Boards are opened where people work.
 *
 * EVERY ID ON THE FORM IS RE-CHECKED IN THE SERVICE. A measure id and a report id both look like
 * integers and both pass `exists`; neither proves the row belongs to this company. The service
 * re-fetches inside the actor's tenant and refuses a mismatch (§74, §94) — none of that is done
 * here, because a controller that validated tenancy itself would be a second implementation of the
 * rule, and the one that matters is the one on the write path.
 *
 * REORDERING IS A PUT, NOT A GET, and takes a list of ids that the service filters against the
 * tenant scope rather than trusting. A stale page posting a tile that has since been deleted moves
 * what it can and leaves the rest, because failing wholesale in front of somebody who is about to
 * present from the screen is the wrong trade.
 */
class DashboardWidgetController extends Controller
{
    public function __construct(
        private readonly DashboardWidgetService $service,
    ) {}

    /**
     * Every board this company has arranged, with each tile previewed for this viewer.
     *
     * Listed through `visibleTo` rather than a bare query, for the same reason the KPI list is:
     * which tiles a company has chosen is itself a statement about the company.
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', DashboardWidget::class);

        $user = $request->user();

        // The ability this person actually holds, not a fixed one. `visibleTo` compiles the scopes
        // attached to the permission it is GIVEN, so naming `dashboard.view` for somebody who holds
        // only `dashboard.view_department` would compile an empty scope set and show them an empty
        // page — a silent wrong answer rather than a refusal, which is the worse of the two.
        $reading = collect(DashboardWidgetPolicy::READING_ABILITIES)
            ->first(fn (string $ability): bool => $user->hasPermission($ability)) ?? 'dashboard.view';

        $widgets = DashboardWidget::query()
            ->visibleTo($user, $reading)
            ->ordered()
            ->with(['kpiDefinition', 'reportDefinition'])
            ->get()
            ->groupBy('board');

        return view('configuration.widgets.index', [
            'boards' => WidgetCatalogue::BOARDS,
            'widgets' => $widgets,
            'kinds' => WidgetCatalogue::kindOptions(),
            'refresh' => WidgetCatalogue::REFRESH,
            'canManage' => $user->can('create', DashboardWidget::class),
            'maxPerBoard' => WidgetCatalogue::MAX_PER_BOARD,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', DashboardWidget::class);

        $widget = new DashboardWidget;
        $widget->board = WidgetCatalogue::isBoard((string) $request->query('board', ''))
            ? (string) $request->query('board')
            : 'executive';
        $widget->kind = 'kpi_tile';

        return view('configuration.widgets.form', $this->formData($request, $widget));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', DashboardWidget::class);

        $widget = $this->service->create($this->payload($request), $request->user());

        return redirect()
            ->route('configuration.widgets.index')
            ->with('status', '“'.$widget->heading().'” is now on the board.');
    }

    public function edit(Request $request, DashboardWidget $widget): View
    {
        $this->authorize('update', $widget);

        return view('configuration.widgets.form', $this->formData($request, $widget));
    }

    public function update(Request $request, DashboardWidget $widget): RedirectResponse
    {
        $this->authorize('update', $widget);

        $this->service->update($widget, $this->payload($request), $request->user());

        return redirect()
            ->route('configuration.widgets.index')
            ->with('status', '“'.$widget->heading().'” has been changed.');
    }

    /**
     * Take a tile off a board without losing how it was set up.
     *
     * Offered next to delete and almost always the right one, for the same reason switching a
     * measure off beats deleting it: a board is rearranged in the ten minutes before a meeting.
     */
    public function toggle(Request $request, DashboardWidget $widget): RedirectResponse
    {
        $this->authorize('update', $widget);

        $this->service->setActive($widget, ! $widget->is_active, $request->user());

        return back()->with('status', $widget->is_active
            ? '“'.$widget->heading().'” is back on the board.'
            : '“'.$widget->heading().'” has been hidden. Its setup is kept.');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $this->authorize('create', DashboardWidget::class);

        $board = (string) $request->input('board', '');

        $moved = $this->service->reorder($board, (array) $request->input('order', []), $request->user());

        return back()->with('status', $moved === 0
            ? 'Nothing moved.'
            : 'The board has been rearranged.');
    }

    public function destroy(Request $request, DashboardWidget $widget): RedirectResponse
    {
        $this->authorize('delete', $widget);

        $heading = $widget->heading();

        $this->service->delete($widget, $request->user());

        return redirect()
            ->route('configuration.widgets.index')
            ->with('status', '“'.$heading.'” has been taken off the board.');
    }

    // ================================================================== plumbing

    /**
     * What the builder may say about a tile.
     *
     * An explicit list rather than `$request->all()`. `organization_id` is the actor's (§74), and
     * there is deliberately no `permission` key at any level — a tile's required permission is
     * derived from its source and there is nowhere to override it (§6, §84).
     *
     * @return array<string,mixed>
     */
    private function payload(Request $request): array
    {
        return [
            'board' => (string) $request->input('board', ''),
            'kind' => (string) $request->input('kind', ''),
            'title' => $request->input('title'),
            'kpi_definition_id' => $request->input('kpi_definition_id'),
            'report_definition_id' => $request->input('report_definition_id'),
            'refresh' => (string) $request->input('refresh', 'live'),
            'width' => (string) $request->input('width', 'third'),
            'row_limit' => $request->input('row_limit', 5),
            'span' => $request->input('span', 12),
            'sort' => (int) $request->input('sort', 0),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /**
     * The measures and reports this person may put on a board.
     *
     * Both lists go through `visibleTo`, which is not a formality: somebody arranging a board
     * should not learn from a select box that the company measures "days since last lost-time
     * injury" (§61). The service re-checks tenancy on save regardless — this list decides what is
     * OFFERED, and the service decides what is ACCEPTED.
     *
     * @return array<string,mixed>
     */
    private function formData(Request $request, DashboardWidget $widget): array
    {
        $user = $request->user();

        return [
            'widget' => $widget,
            'boards' => WidgetCatalogue::BOARDS,
            'kinds' => WidgetCatalogue::KINDS,
            'refreshOptions' => WidgetCatalogue::REFRESH,
            'widths' => WidgetCatalogue::WIDTHS,
            'maxRows' => WidgetCatalogue::MAX_ROW_LIMIT,
            'maxSpan' => WidgetCatalogue::MAX_SPAN,
            'measures' => KpiDefinition::query()
                ->visibleTo($user, 'kpi.view')
                ->active()
                ->ordered()
                ->get(['id', 'name', 'scope', 'entity_type']),
            'reports' => ReportDefinition::query()
                ->visibleTo($user, 'report.view')
                ->active()
                ->ordered()
                ->get(['id', 'name', 'entity_type']),
        ];
    }
}
