<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\PermitTypeRequest;
use App\Models\PermitType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The permit catalogue — configuration, and the reason this platform can be sold twice.
 *
 * THIS IS NOT A REGISTER AND IT DOES NOT EXTEND RegisterController. A permit type has no number, no
 * status, no workflow and no reach of its own; it is a row in a settings table that the permit
 * register reads. Giving it a status graph would mean approving a piece of configuration through
 * the same machinery that approves work on a live plant, which helps nobody.
 *
 * EDITING THE TEMPLATE IS SAFETY ADMINISTRATION, NOT DATA ENTRY, and PermitTypePolicy folds every
 * write onto `permit.manage` for exactly that reason. `precaution_template` is the checklist that
 * will be copied onto every future confined-space permit; somebody holding `permit.edit` for their
 * day-to-day issuing must not be able to take the gas test off it.
 *
 * THE TEMPLATE IS COPIED, NOT REFERENCED, and the copy happens in PermitService at creation. That
 * is why this screen can be edited freely: nothing here reaches backwards into a permit somebody
 * has already signed. A register whose evidence changed when its configuration changed would be
 * worthless in the only conversation it exists for (§59).
 *
 * DEACTIVATE, DON'T DELETE. The policy refuses to delete a type that still has permits against it,
 * because the alternative is a set of historical permits whose heading has gone blank. `is_active`
 * is how a company retires a permit it no longer issues.
 */
class PermitTypeController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', PermitType::class);

        $user = $request->user();

        $types = $this->compiler
            ->apply(PermitType::query(), $user, 'permit.view')
            ->withCount('permits')
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return view('permit_types.index', [
            'types' => $types,
            'canManage' => $user->can('create', PermitType::class),
        ]);
    }

    public function show(Request $request, PermitType $permitType): View
    {
        $this->authorize('view', $permitType);

        $user = $request->user();

        return view('permit_types.show', [
            'type' => $permitType,
            'precautions' => $permitType->defaultPrecautions(),
            'permitCount' => $permitType->permits()->count(),
            'timeline' => $this->activity->timelineFor($permitType)->limit(20)->get(),
            'canEdit' => $user->can('update', $permitType),
            'canDelete' => $user->can('delete', $permitType),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', PermitType::class);

        return view('permit_types.create', [
            'type' => new PermitType([
                'is_active' => true,
                'requires_gas_test' => false,
                'requires_isolation' => false,
                'default_duration_hours' => 12,
            ]),
            'precautions' => [],
        ]);
    }

    public function store(PermitTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', PermitType::class);

        $user = $request->user();

        $type = new PermitType($request->permitTypeAttributes());
        $type->organization_id = $user->organization_id;
        $type->save();

        $this->activity->record(
            $type,
            'created',
            $user->name.' added the '.$type->displayName().' permit type.',
            ['precautions' => count($type->defaultPrecautions())],
            $user,
        );

        return redirect()->route('permit-types.show', $type)
            ->with('status', $type->displayName().' has been added.');
    }

    public function edit(Request $request, PermitType $permitType): View
    {
        $this->authorize('update', $permitType);

        return view('permit_types.edit', [
            'type' => $permitType,
            'precautions' => $permitType->defaultPrecautions(),
        ]);
    }

    /**
     * Amend the type.
     *
     * The precaution count is recorded on the timeline rather than the precautions themselves. What
     * an auditor needs from this record is that somebody changed the confined-space template on a
     * Tuesday and how much shorter it got; the text of the checklist is on the record in front of
     * them, and duplicating it into the activity log would make the log unreadable within a year.
     */
    public function update(PermitTypeRequest $request, PermitType $permitType): RedirectResponse
    {
        $this->authorize('update', $permitType);

        $before = count($permitType->defaultPrecautions());

        $permitType->fill($request->permitTypeAttributes())->save();

        $this->activity->record(
            $permitType,
            'updated',
            $request->user()->name.' updated the '.$permitType->displayName().' permit type.',
            ['precautions_before' => $before, 'precautions_after' => count($permitType->defaultPrecautions())],
            $request->user(),
        );

        return redirect()->route('permit-types.show', $permitType)
            ->with('status', $permitType->displayName().' has been updated.');
    }

    public function destroy(Request $request, PermitType $permitType): RedirectResponse
    {
        $this->authorize('delete', $permitType);

        $name = $permitType->displayName();

        $this->activity->record(
            $permitType,
            'deleted',
            $request->user()->name.' deleted the unused '.$name.' permit type.',
            [],
            $request->user(),
        );

        $permitType->delete();

        return redirect()->route('permit-types.index')
            ->with('status', $name.' has been deleted.');
    }
}
