<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReportDefinition;
use App\Models\SavedFilter;
use App\Services\SavedFilterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Keeping a question worth asking twice (§54, docs/11 §2).
 *
 * EVERY ROUTE HERE HANGS OFF A REPORT, and that is a security decision rather than a URL style. The
 * register a filter asks about is read from the report the person is standing on — never from the
 * request — so there is no `entity_type` anybody can post to attach conditions to a register they
 * were not looking at, and no combination of ids that produces a filter whose conditions belong to
 * one table and whose name sits on another (§74, §84).
 *
 * APPLYING A SAVED FILTER IS AN ORDINARY RUN OF THE REPORT. This controller does not fetch a single
 * row. `apply()` reads the stored conditions, checks them against the catalogue on the way out, and
 * redirects to the run screen with them in the query string — where they meet exactly the same
 * whitelist, the same compiler and the same `visibleTo()` as conditions somebody typed by hand. A
 * saved filter is therefore incapable of reaching a row an ad-hoc filter could not, because after
 * the redirect there is no difference between the two.
 *
 * WHICH IS ALSO WHY THE REDIRECT IS NOT A SHORTCUT WORTH REMOVING. Running the report here and
 * rendering it would be one fewer request and would quietly create a second run path with its own
 * chance of forgetting the scope — the mistake §78 names in the approval engine, transplanted.
 *
 * THESE ROUTES CARRY `report.view` AND NO NEW PERMISSION. Saving your own shortlist is not an
 * authority a company should have to grant; the only screen that offers it is the report run screen,
 * which already required that permission to reach. Who may see a SHARED one is `SavedFilterPolicy`'s
 * question, and who may see the rows it selects is never this module's question at all.
 */
class SavedFilterController extends Controller
{
    public function __construct(
        private readonly SavedFilterService $service,
    ) {}

    /**
     * Save what is currently on the screen.
     *
     * The conditions come from the same `filters[]` shape the run screen already posts, so what gets
     * saved is what the person was looking at — the property docs/11 §2 asks for when it says
     * filters are shared between screen and export.
     */
    public function store(Request $request, ReportDefinition $report): RedirectResponse
    {
        $this->authorize('view', $report);
        $this->authorize('create', SavedFilter::class);

        try {
            $filter = $this->service->create([
                'name' => (string) $request->input('name', ''),

                // From the report, not the request. See the class docblock.
                'entity_type' => $report->entity_type,

                'filters' => (array) $request->input('filters', []),
                'visibility' => (string) $request->input('visibility', 'private'),
            ], $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['saved_filter' => $e->getMessage()]);
        }

        return redirect()
            ->to($this->runUrl($report, $filter))
            ->with('status', '“'.$filter->name.'” is saved. '.$this->sharingNote($filter));
    }

    /**
     * Rename it, or change who can see it.
     *
     * The conditions are deliberately NOT re-posted here. Changing what a shared name selects, while
     * the name stays the same, is the one change to a saved view that is invisible to the people
     * using it; re-saving is a new filter, which is visible.
     */
    public function update(Request $request, ReportDefinition $report, SavedFilter $savedFilter): RedirectResponse
    {
        $this->authorize('view', $report);
        $this->authorize('update', $savedFilter);

        $this->guardRegister($report, $savedFilter);

        try {
            $this->service->update($savedFilter, [
                'name' => (string) $request->input('name', $savedFilter->name),
                'entity_type' => $savedFilter->entity_type,
                'filters' => $savedFilter->conditions(),
                'visibility' => (string) $request->input('visibility', $savedFilter->visibility),
            ], $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['saved_filter' => $e->getMessage()]);
        }

        return back()->with('status', '“'.$savedFilter->name.'” has been changed. '.$this->sharingNote($savedFilter));
    }

    public function destroy(Request $request, ReportDefinition $report, SavedFilter $savedFilter): RedirectResponse
    {
        $this->authorize('view', $report);
        $this->authorize('delete', $savedFilter);

        $this->guardRegister($report, $savedFilter);

        $name = $savedFilter->name;

        $this->service->delete($savedFilter, $request->user());

        return redirect()
            ->route('configuration.reports.show', $report)
            ->with('status', '“'.$name.'” has been removed.');
    }

    /**
     * Run the report with a saved view's conditions.
     *
     * A redirect, not a render. What comes back is the ordinary run screen, so the conditions are
     * re-checked, the rows are re-derived, and the query string shows the person exactly what they
     * are now looking at — which they can then edit by hand like any other filter.
     */
    public function apply(Request $request, ReportDefinition $report, SavedFilter $savedFilter): RedirectResponse
    {
        $this->authorize('view', $report);
        $this->authorize('view', $savedFilter);

        $this->guardRegister($report, $savedFilter);

        // A VIEW THAT NO LONGER NARROWS ANYTHING IS REFUSED, NOT APPLIED. `conditions()` drops any
        // condition on a field the register has stopped offering — an upgrade withdrew it, a custom
        // field was deleted, a module was switched off — and if that leaves nothing, redirecting
        // would run the report unfiltered. The person clicked "Civil only" and would be looking at
        // the whole company, under a heading that says otherwise, with a row count they will quote.
        // Of every way this module can fail, that is the one nobody would catch.
        if ($savedFilter->conditions() === []) {
            return redirect()
                ->route('configuration.reports.show', $report)
                ->withErrors(['saved_filter' => '“'.$savedFilter->name.'” no longer works: the fields it '
                    .'asked about are not offered on this register any more. It has not been applied — '
                    .'remove it and save the conditions again.']);
        }

        return redirect()->to($this->runUrl($report, $savedFilter));
    }

    // ================================================================== helpers

    /**
     * A filter answers about ONE register, and it must be that report's.
     *
     * Two valid ids, wrongly paired, is the shape an IDOR attempt takes when every individual id
     * passes its own check (§94). "Status is open" means something on documents and something else
     * on purchase orders, and a mismatch here would apply one register's conditions to another's
     * columns — which the compiler would refuse anyway, but refusing it by accident downstream is
     * not the same as refusing it on purpose here.
     */
    private function guardRegister(ReportDefinition $report, SavedFilter $savedFilter): void
    {
        abort_unless($savedFilter->entity_type === $report->entity_type, 404);
    }

    private function runUrl(ReportDefinition $report, SavedFilter $filter): string
    {
        return route('configuration.reports.show', $report)
            .'?'.http_build_query(['filters' => $filter->conditions()]);
    }

    private function sharingNote(SavedFilter $filter): string
    {
        return $filter->isShared()
            // Said out loud, because sharing a view puts its NAME on other people's screens, and
            // the rest of the sentence is the thing people get wrong about it.
            ? $filter->visibilityLabel().' can see it. They will see their own rows, not yours.'
            : 'Only you can see it.';
    }
}
