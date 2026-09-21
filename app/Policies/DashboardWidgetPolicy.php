<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Who arranges the boards (§6, §52, §53, §73).
 *
 * ARRANGING IS NOT READING, AND NEITHER IS THE FIGURE. Three separate things, deliberately kept
 * apart. `dashboard.manage` decides who may place a tile. `dashboard.view_executive` and
 * `dashboard.view_department` decide who may open a board at all. And what any given tile SAYS is
 * decided by neither: WidgetComposer counts it through the reader's own visibility of the register
 * behind it, so a person with every dashboard permission and no access to incidents opens the
 * executive board and finds no incident tile on it.
 *
 * MANAGING IS THE DANGEROUS HALF, and not because a tile grants anything — it grants nothing, and
 * placing one moves no record and opens no door. The danger is that the arrangement decides what an
 * entire company looks at every morning. A tile quietly retitled, or a measure swapped for one with
 * a wider filter, changes what the board believes without changing a single number's provenance;
 * the tile is still counted correctly, for the right person, under the wrong heading. That is why
 * the model is audited and why this sits behind its own ability rather than under `view`.
 *
 * THERE IS NO `export`. A board is not a document. What a board shows is a set of figures and
 * reports which each carry their own export permission, and adding one here would be a second way
 * to get a report out from under `report.export` (§78's argument, applied to exports).
 */
class DashboardWidgetPolicy extends ScopedPolicy
{
    /**
     * The abilities that let somebody read how the boards are put together.
     *
     * Any of them, not all: the three are separate doors into the same building. Somebody who may
     * open the executive board and nothing else still has a reason to see which tiles are on it,
     * and `dashboard.manage` is listed because a person who may rearrange a board must be able to
     * look at it first — an arranger locked out of the arrangement is not a security posture.
     *
     * @var array<int,string>
     */
    public const READING_ABILITIES = [
        'dashboard.view',
        'dashboard.view_executive',
        'dashboard.view_department',
        'dashboard.manage',
    ];

    protected string $module = 'dashboard';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'manage',
        'update' => 'manage',
        'delete' => 'manage',
        'restore' => 'manage',
        'forceDelete' => 'manage',
    ];

    /**
     * Reading the arrangement, which is wider than `dashboard.view` alone.
     *
     * Overridden rather than mapped because `$abilityMap` names ONE ability per action and this is
     * genuinely a set. Left unoverridden, a departmental manager holding `dashboard.view_department`
     * would follow a sidebar link the navigation had shown them — it is built from the same set —
     * into a 403, which is the specific failure §96 exists to prevent.
     *
     * This widens READING, never writing. `create`, `update` and `delete` stay on `dashboard.manage`
     * through the map above, so the extra abilities buy a look at the list and nothing more.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(...self::READING_ABILITIES);
    }
}
