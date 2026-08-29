<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\LocationRequest;
use App\Models\Location;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sites, offices, yards and warehouses (§3).
 *
 * Locations sit outside the org tree entirely — see Location::accessOrgUnitColumn(), which returns
 * null. Reading the list is organization-wide for everyone who holds location.view, because a
 * site appears on your own attendance record and a directory that hid it would be useless.
 * Creating and editing one still requires organization reach, which is why the list is broad and
 * the write paths are not.
 */
class LocationController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Location::class);

        $user = $request->user();

        $query = $this->compiler->apply(Location::query(), $user, 'location.view')
            ->withCount('employees');

        if ($term = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($term) {
                $like = '%'.$term.'%';
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('city', 'like', $like);
            });
        }

        if (($type = (string) $request->query('type', '')) !== ''
            && in_array($type, LocationRequest::types(), true)) {
            $query->where('type', $type);
        }

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return view('locations.index', [
            'locations' => $query->orderBy('name')->paginate(30)->withQueryString(),
            'types' => $this->typeOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'type' => $type,
                'include_inactive' => $request->boolean('include_inactive'),
            ],
            'canCreate' => $user->can('create', Location::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Location::class);

        return view('locations.create', [
            'location' => new Location([
                'type' => 'site',
                'is_active' => true,
                'country' => $request->user()->organization?->country,
            ]),
            'types' => $this->typeOptions(),
        ]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        $this->authorize('create', Location::class);

        $location = new Location;
        $location->organization_id = $request->user()->organization_id;   // never from input (§74)
        $location->fill($request->validated())->save();

        $this->activity->record($location, 'created', $request->user()->name.' added '.$location->name);

        return redirect()->route('locations.index')
            ->with('status', $location->name.' has been added.');
    }

    public function edit(Request $request, Location $location): View
    {
        $this->authorize('update', $location);

        return view('locations.edit', [
            'location' => $location,
            'types' => $this->typeOptions(),
        ]);
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $this->authorize('update', $location);

        $location->fill($request->validated())->save();

        $this->activity->record($location, 'updated', $request->user()->name.' updated '.$location->name);

        return redirect()->route('locations.index')
            ->with('status', $location->name.' has been updated.');
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        // The policy refuses while people are still posted there.
        $this->authorize('delete', $location);

        $name = $location->name;

        $this->activity->record($location, 'deleted', $request->user()->name.' removed '.$name);

        $location->delete();

        return redirect()->route('locations.index')
            ->with('status', $name.' has been removed.');
    }

    /**
     * @return array<string,string>
     */
    private function typeOptions(): array
    {
        return collect(LocationRequest::types())
            ->mapWithKeys(fn (string $type) => [$type => ucfirst($type)])
            ->all();
    }
}
