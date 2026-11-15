<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Models\Asset;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The site map (§3): every location this company has given coordinates to, and what is at it.
 *
 * WHY THIS IS A READ-ONLY VIEW AND NOT A MODULE. Nothing here owns a record. Coordinates and the
 * geofence radius are edited on the location itself, where they already were; this screen is a
 * second way of looking at data five other modules own. That is also why `sitemap` carries exactly
 * one permission: there is nothing to create, and a `sitemap.edit` would be a second door to the
 * location form with its own chance of disagreeing with the first.
 *
 * THE AGGREGATION PROBLEM, WHICH IS THE WHOLE DESIGN. A pin can say "9 assets, 23 people, 4 on
 * site now". Each of those is a different module with a different permission, and a viewer who
 * holds `sitemap.view` has been granted nothing about assets. So a count is produced only when its
 * own module permission is held, and OMITTED — not zeroed — when it is not. Zero and "you may not
 * ask" must not render identically, because a zero that means "not permitted" is still a sentence
 * about how many there are. §6 and §61: the map may not become a side channel that answers
 * questions the register itself would refuse.
 *
 * AND THE COUNTS GO THROUGH THE SAME COMPILER THE REGISTERS DO. Every rollup is a scoped query via
 * ScopeCompiler under its module's own permission, so a site manager whose reach is one department
 * sees the number of projects they could actually open, not the number that exist. A `withCount`
 * on a plain relation would have been shorter and would have counted rows the viewer is not allowed
 * to see — a leak with no error message, which is the kind this repository's tests exist to catch.
 */
class MapController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
    ) {}

    public function index(): View
    {
        return view('map.index', [
            'tiles' => config('map.tiles'),
            'defaultView' => config('map.default_view'),
            'endpoint' => route('map.locations'),
        ]);
    }

    /**
     * The locations, as GeoJSON.
     *
     * Separate from the page so the map fetches its data rather than having it inlined into the
     * HTML: the payload is the part that depends on who is asking, and keeping it on its own URL
     * means it is authorized on its own and can be re-fetched without a page load.
     *
     * A viewer holding `sitemap.view` but not `location.view` gets an empty collection rather than
     * an error. That is the compiler failing closed, and it is the correct answer — the route gate
     * says they may open this screen, and the data gate says there is nothing on it for them.
     */
    public function locations(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $locations = $this->compiler->apply(Location::query(), $user, 'location.view')
            ->where('is_active', true)
            // Both columns are nullable and most companies fill them in over months rather than on
            // day one. A location without coordinates is not an error and not a pin at 0,0 in the
            // Gulf of Guinea — it is simply absent from the map and present in the register.
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type', 'city', 'latitude', 'longitude', 'geofence_radius']);

        $rollups = $this->rollups($user, $locations->pluck('id')->all());

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $locations->map(fn (Location $location): array => [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    // GeoJSON is [longitude, latitude] — the opposite order to how every mapping
                    // library takes a centre point, and to how anybody says it out loud. Reversing
                    // these does not throw: it silently puts Lagos in the Indian Ocean.
                    'coordinates' => [(float) $location->longitude, (float) $location->latitude],
                ],
                'properties' => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'code' => $location->code,
                    'type' => $location->type,
                    'city' => $location->city,
                    'radius' => $location->geofence_radius !== null
                        ? (int) $location->geofence_radius
                        : null,
                    'metrics' => $this->metricsFor((int) $location->id, $rollups),
                ],
            ])->all(),
        ]);
    }

    /**
     * What may be counted, and under which permission.
     *
     * The permission named here is BOTH the gate and the scope: it decides whether the number is
     * produced at all, and which records it counts. Adding a rollup means adding one row — and
     * means choosing the permission that already governs those records, never a new one.
     *
     * @return array<string,array{permission:string,model:class-string,label:string,constrain:?callable}>
     */
    private function definitions(): array
    {
        return [
            'projects' => [
                'permission' => 'project.view',
                'model' => Project::class,
                'label' => 'Projects',
                'constrain' => null,
            ],
            'assets' => [
                'permission' => 'asset.view',
                'model' => Asset::class,
                'label' => 'Assets',
                'constrain' => null,
            ],
            'people' => [
                'permission' => 'employee.view',
                'model' => Employee::class,
                'label' => 'People based here',
                'constrain' => null,
            ],
            'on_site' => [
                'permission' => 'attendance.view',
                'model' => AttendanceRecord::class,
                'label' => 'On site now',
                // Clocked in today and not yet out. `work_date` rather than a range on
                // `clock_in_at`, because that is the column the attendance module itself treats as
                // the day a shift belongs to — a night shift crossing midnight is one work date,
                // and counting by timestamp would file half of it under tomorrow.
                'constrain' => fn (Builder $query) => $query
                    ->whereDate('work_date', now()->toDateString())
                    ->whereNull('clock_out_at'),
            ],
            'visitors' => [
                'permission' => 'visitor.view',
                'model' => Visitor::class,
                'label' => 'Visitors on site',
                'constrain' => fn (Builder $query) => $query
                    ->whereNotNull('checked_in_at')
                    ->whereNull('checked_out_at'),
            ],
        ];
    }

    /**
     * One grouped query per permitted rollup — five at the very most, and none for a viewer who
     * holds none of them.
     *
     * @param  array<int,int>  $locationIds
     * @return array<string,array{label:string,counts:array<int,int>}>
     */
    private function rollups(User $user, array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $rollups = [];

        foreach ($this->definitions() as $key => $definition) {
            // Gate first, query second. The permission check is cheap and the absence of it is the
            // whole point: no permission, no query, no key in the payload.
            if (! $user->hasPermission($definition['permission'])) {
                continue;
            }

            $model = $definition['model'];

            /** @var Builder $query */
            $query = $model::query();

            $query = $this->compiler->apply($query, $user, $definition['permission'])
                ->whereIn('location_id', $locationIds);

            if ($definition['constrain'] !== null) {
                ($definition['constrain'])($query);
            }

            $counts = $query
                // `reorder()` is defensive, and deliberately so. Nothing on these five models or
                // in the compiler adds a default ordering TODAY, so the call strips nothing — but
                // an ORDER BY on a column that is neither grouped nor aggregated is rejected
                // outright under ONLY_FULL_GROUP_BY, which config/database.php turns on via
                // `strict => true`. So the day somebody gives Asset a `latest()` default or the
                // compiler starts ordering its output, the failure would land HERE, on a screen
                // nobody was editing, as a 500 on production MySQL that the SQLite test suite
                // cannot reproduce. One call now costs nothing and closes that off; the ordering
                // is meaningless on a count in any case.
                ->reorder()
                ->selectRaw('location_id, COUNT(*) as aggregate')
                ->groupBy('location_id')
                ->pluck('aggregate', 'location_id');

            $rollups[$key] = [
                'label' => $definition['label'],
                'counts' => $counts->map(fn ($value): int => (int) $value)->all(),
            ];
        }

        return $rollups;
    }

    /**
     * The metrics for one pin, in a fixed order, including the zeroes.
     *
     * A permitted rollup that counted nothing at this location IS reported, as 0. That is the
     * distinction the aggregation rule turns on: "0 assets" is an answer the viewer is entitled to,
     * while a missing `assets` key means they were never entitled to ask. Collapsing the two would
     * hand the second meaning to the first.
     *
     * @param  array<string,array{label:string,counts:array<int,int>}>  $rollups
     * @return array<int,array{key:string,label:string,value:int}>
     */
    private function metricsFor(int $locationId, array $rollups): array
    {
        $metrics = [];

        foreach ($rollups as $key => $rollup) {
            $metrics[] = [
                'key' => $key,
                'label' => $rollup['label'],
                'value' => (int) ($rollup['counts'][$locationId] ?? 0),
            ];
        }

        return $metrics;
    }
}
