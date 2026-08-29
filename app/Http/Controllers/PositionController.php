<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\PositionRequest;
use App\Models\OrgUnit;
use App\Models\Position;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Job titles (§3).
 *
 * Reference data, but not global reference data: a position belongs to an org unit, so the same
 * scope rules apply as anywhere else. A department administrator maintains their department's
 * titles and sees no others.
 */
class PositionController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Position::class);

        $user = $request->user();

        $query = $this->compiler->apply(Position::query(), $user, 'position.view')
            ->with('orgUnit:id,name')
            ->withCount('employees');

        if ($term = trim((string) $request->query('q', ''))) {
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', '%'.$term.'%')
                    ->orWhere('code', 'like', '%'.$term.'%');
            });
        }

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return view('positions.index', [
            'positions' => $query->orderBy('title')->paginate(30)->withQueryString(),
            'filters' => [
                'q' => $request->query('q'),
                'include_inactive' => $request->boolean('include_inactive'),
            ],
            'canCreate' => $user->can('create', Position::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Position::class);

        return view('positions.create', [
            'position' => new Position(['is_active' => true]),
            'units' => $this->unitOptions($request),
        ]);
    }

    public function store(PositionRequest $request): RedirectResponse
    {
        $this->authorize('create', Position::class);

        $data = $request->validated();

        $this->assertUnitIsReachable($data['org_unit_id'] ?? null);

        $position = new Position;
        $position->organization_id = $request->user()->organization_id;   // never from input (§74)
        $position->fill($data)->save();

        $this->activity->record($position, 'created', $request->user()->name.' created '.$position->title);

        return redirect()->route('positions.index')
            ->with('status', $position->title.' has been created.');
    }

    public function edit(Request $request, Position $position): View
    {
        $this->authorize('update', $position);

        return view('positions.edit', [
            'position' => $position,
            'units' => $this->unitOptions($request),
        ]);
    }

    public function update(PositionRequest $request, Position $position): RedirectResponse
    {
        $this->authorize('update', $position);

        $data = $request->validated();

        $this->assertUnitIsReachable($data['org_unit_id'] ?? null);

        $position->fill($data)->save();

        $this->activity->record($position, 'updated', $request->user()->name.' updated '.$position->title);

        return redirect()->route('positions.index')
            ->with('status', $position->title.' has been updated.');
    }

    public function destroy(Request $request, Position $position): RedirectResponse
    {
        // The policy refuses while anyone still holds the title — deleting it would leave those
        // people with a job description that points nowhere.
        $this->authorize('delete', $position);

        $title = $position->title;

        $this->activity->record($position, 'deleted', $request->user()->name.' removed '.$title);

        $position->delete();

        return redirect()->route('positions.index')
            ->with('status', $title.' has been removed.');
    }

    /**
     * Moving a title into a unit the actor cannot reach would file it out of their own sight —
     * and, since positions carry unit-scoped visibility, out of their authority to correct.
     */
    private function assertUnitIsReachable(int|string|null $unitId): void
    {
        if ($unitId !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }
    }

    /**
     * @return array<int|string,string>
     */
    private function unitOptions(Request $request): array
    {
        return $this->compiler
            ->apply(OrgUnit::query()->where('is_active', true), $request->user(), 'org_unit.view')
            ->orderBy('path')->get()
            ->mapWithKeys(fn (OrgUnit $u) => [
                $u->id => str_repeat('— ', max(0, (int) $u->depth)).$u->name,
            ])->all();
    }
}
