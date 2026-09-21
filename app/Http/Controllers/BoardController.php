<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ActorProfile;
use App\Engines\Dashboard\WidgetComposer;
use App\Models\OrgUnit;
use App\Support\Enums\OrgUnitType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The boards themselves — what a company looks at every morning (§51, §52, §53).
 *
 * THIS CONTROLLER COUNTS NOTHING. Every figure on every board comes back from WidgetComposer,
 * which counts it through the visibility of whoever opened the page. That is the §90 rule applied
 * to the one screen most likely to break it: a command center is exactly where somebody reaches
 * for a quick `Employee::count()` because the number is "just for the header", and the result is a
 * page that tells a department head the company has 312 people and then shows them 41.
 *
 * WHICH DEPARTMENT A BOARD IS ABOUT IS NOT DECIDED BY THE URL. The id in the address bar is a
 * request; what settles it is whether that unit survives `visibleTo`, re-asked on every load (§84).
 * A department head who edits the id to a sister department's gets a 404 — the same answer they
 * would get for a unit in another company, and deliberately the same, because distinguishing
 * "exists but not yours" from "does not exist" is how an id becomes an oracle (§75).
 *
 * THE EMPTY BOARD IS A SCREEN, NOT AN ACCIDENT. A company that has just been set up has arranged
 * no tiles, and the first person to open the executive board is usually the person who will
 * arrange it. So an empty board says what a board is for and — if they may arrange one — points at
 * the builder (§73). A blank page with a heading teaches people the feature is broken.
 */
class BoardController extends Controller
{
    /**
     * Which altitudes of unit can have a board of their own.
     *
     * Divisions and departments; not sections or teams. Not an arbitrary line: a board is a page
     * somebody opens every morning, and a company with two hundred teams would have two hundred of
     * them, each showing the same tiles over a handful of records. §53 asks for departmental
     * dashboards, and a division is the altitude a department reports to.
     *
     * @var array<int,OrgUnitType>
     */
    private const BOARD_ALTITUDES = [OrgUnitType::Division, OrgUnitType::Department];

    public function __construct(
        private readonly WidgetComposer $composer,
        private readonly ActorProfile $profile,
    ) {}

    /**
     * The whole company, for the people entitled to look at it that way.
     *
     * No scope id, because the scope IS the company. Note what this does NOT do: it does not check
     * that the viewer can see every record behind every tile. That check belongs to each tile and
     * happens per figure, which is why somebody with `dashboard.view_executive` and a narrow view
     * of the registers gets a real board carrying their own numbers rather than a refusal.
     */
    public function executive(Request $request): View
    {
        $user = $request->user();

        return view('boards.executive', [
            'board' => 'executive',
            'heading' => 'Executive board',
            'subheading' => 'The whole company, as far as you can see it.',
            'tiles' => $this->composer->compose('executive', $user),
            'canArrange' => $user->hasPermission('dashboard.manage'),
            'units' => $this->boardableUnits($request),
        ]);
    }

    /**
     * One department, chosen from the ones this person may open.
     *
     * The optional parameter is what makes the sidebar link possible: §96 builds the menu from
     * permissions and cannot know which department to name, so a bare visit lands somebody on
     * their own. Somebody with no unit and no reachable unit is told so rather than shown an empty
     * board, which would read as "your department has nothing in it".
     */
    public function department(Request $request, ?OrgUnit $orgUnit = null): View|RedirectResponse
    {
        $user = $request->user();
        $units = $this->boardableUnits($request);

        if ($orgUnit === null) {
            $landing = $this->defaultUnitFor($request, $units);

            if ($landing === null) {
                return view('boards.department', [
                    'board' => 'department',
                    'heading' => 'Department board',
                    'subheading' => 'No department to show.',
                    'unit' => null,
                    'tiles' => [],
                    'canArrange' => $user->hasPermission('dashboard.manage'),
                    'units' => $units,
                ]);
            }

            return redirect()->route('boards.department', $landing);
        }

        // Re-asked here rather than trusted from the binding. Implicit binding proves the row
        // exists in this tenant and nothing more; whether THIS person may open THAT department is
        // a separate question with a separate answer (§84).
        abort_unless($units->contains(fn (OrgUnit $unit): bool => $unit->is($orgUnit)), 404);

        return view('boards.department', [
            'board' => 'department',
            'heading' => $orgUnit->name,
            'subheading' => 'This department, as far as you can see it.',
            'unit' => $orgUnit,
            'tiles' => $this->composer->compose('department', $user, $orgUnit->getKey()),
            'canArrange' => $user->hasPermission('dashboard.manage'),
            'units' => $units,
        ]);
    }

    // ================================================================== plumbing

    /**
     * The departments this person may open a board for.
     *
     * Through `visibleTo` on `org_unit.view`, which is the same permission the org chart is read
     * under — so the picker on a board offers exactly the units the chart offers, and somebody
     * cannot discover from a dropdown that a department exists that the chart never showed them
     * (§61 applied to a select box).
     *
     * @return Collection<int,OrgUnit>
     */
    private function boardableUnits(Request $request): Collection
    {
        $user = $request->user();

        if (! $user->hasPermission('org_unit.view')) {
            return collect();
        }

        return OrgUnit::query()
            ->visibleTo($user, 'org_unit.view')
            ->active()
            ->whereIn('type', array_map(fn (OrgUnitType $type): string => $type->value, self::BOARD_ALTITUDES))
            ->orderBy('name')
            ->get();
    }

    /**
     * Where a bare visit to the department board lands.
     *
     * Their own department first — the overwhelmingly common case, and the one that makes the
     * sidebar link mean something. Failing that, the first they may open, because somebody with no
     * unit of their own who oversees four departments should land on one of them rather than on an
     * apology.
     *
     * @param  Collection<int,OrgUnit>  $units
     */
    private function defaultUnitFor(Request $request, Collection $units): ?OrgUnit
    {
        $mine = $request->user()->employee?->org_unit_id;

        if ($mine !== null) {
            $own = $units->first(fn (OrgUnit $unit): bool => (int) $unit->getKey() === (int) $mine);

            if ($own !== null) {
                return $own;
            }

            // Their own unit is a section or a team, so it has no board of its own. The department
            // it sits under is the board they mean, and walking up is the difference between a
            // working link and one that apologises to most of the company. `ancestorOrgUnitIds`
            // is the same cached walk the access engine does, nearest ancestor first, so the board
            // somebody lands on is the one their department-scoped permissions are about.
            $ancestors = array_map('intval', $this->profile->ancestorOrgUnitIds($request->user()));

            foreach ($ancestors as $ancestorId) {
                $ancestor = $units->first(fn (OrgUnit $unit): bool => (int) $unit->getKey() === $ancestorId);

                if ($ancestor !== null) {
                    return $ancestor;
                }
            }
        }

        return $units->first();
    }
}
