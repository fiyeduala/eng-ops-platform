<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Http\Requests\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Status;
use App\Services\AnnouncementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Announcements (§18).
 *
 * The broadcast counterpart to a memo, and the module where the two ideas the Announcement model
 * separates have to stay separated in the query as well:
 *
 *   visibleTo()   — MAY I see this. The ordinary scope-compiled reach, plus the draft curtain on
 *                   `published_at`. This is the boundary and it is never optional.
 *   addressedTo() — is this FOR me. Routing, which reads UP the org tree: a notice pinned to
 *                   Engineering is for everyone under Engineering. This is a FILTER, applied on top
 *                   of the boundary and never instead of it.
 *
 * Every list below is `compiler->apply(...)` first and `addressedTo()` second, in that order, so a
 * bug in the routing half can only ever hide a notice from somebody — never show them one.
 *
 * Publishing is symmetrical here in a way memos are not: a notice can come back OFF the board, and
 * AnnouncementService clears `published_at` when it does. A published announcement stays EDITABLE —
 * a wrong date on a site-closure notice should be corrected in place, not superseded by a second
 * notice nobody reads — but it cannot be deleted, because the record of what the company was told
 * outlives the notice's usefulness (§59).
 */
class AnnouncementController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly StatusMachine $statuses,
        private readonly AnnouncementService $announcements,
        private readonly NumberGenerator $numbers,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Announcement::class);

        $user = $request->user();

        $query = $this->compiler->apply(Announcement::query(), $user, 'announcement.view')
            ->with([
                'status:id,label,color,is_terminal',
                'project:id,name,number',
                'orgUnit:id,name',
                'creator:id,name',
                'publisher:id,name',
            ]);

        $query->search((string) $request->query('q', ''));

        if ($projectId = (int) $request->query('project', 0)) {
            $query->where('project_id', $projectId);
        }

        if ($statusId = (int) $request->query('status', 0)) {
            $query->where('status_id', $statusId);
        }

        $view = (string) $request->query('view', 'current');

        match ($view) {
            // The board: live, unexpired, and aimed at this person's part of the company.
            'current' => $query->current()->addressedTo($user),

            // Everything live anywhere they can reach — a director checking what has gone out.
            'live' => $query->current(),
            'mine' => $query->where('created_by', $user->id),
            'drafts' => $query->whereNull('published_at'),
            'all' => $query,
            default => $query->current()->addressedTo($user),
        };

        /** @var LengthAwarePaginator $announcements */
        $announcements = $query
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('announcements.index', [
            'announcements' => $announcements,
            'statuses' => $this->statuses->optionsFor('announcement'),
            'projects' => $this->projectOptions($request),
            'views' => $this->viewOptions(),
            'filters' => [
                'q' => $request->query('q'),
                'project' => $request->query('project'),
                'status' => $request->query('status'),
                'view' => $view,
            ],
            'canCreate' => $user->can('create', Announcement::class),
        ]);
    }

    public function show(Request $request, Announcement $announcement): View
    {
        $this->authorize('view', $announcement);

        $user = $request->user();

        $announcement->loadMissing(['status', 'project', 'orgUnit', 'creator', 'publisher']);

        return view('announcements.show', [
            'announcement' => $announcement,
            'timeline' => $this->activity->timelineFor($announcement)->limit(25)->get(),
            'transitions' => $this->statuses->available('announcement', $announcement->status_id, $user),
            'canEdit' => $user->can('update', $announcement),

            // "May move this notice's state at all", which is the question the view is asking when
            // it decides whether to render the status form. Only one of the three can be true at a
            // time — they are state-specific — so asking just `publish` here would hide the form on
            // every notice already on the board, which is every notice anybody wants to retire.
            'canPublish' => $user->can('publish', $announcement)
                || $user->can('unpublish', $announcement)
                || $user->can('archive', $announcement),

            'canDelete' => $user->can('delete', $announcement),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Announcement::class);

        return view('announcements.create', [
            'announcement' => new Announcement(['urgency' => 'normal', 'is_pinned' => false]),
            ...$this->formOptions($request, null),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $this->authorize('create', Announcement::class);

        $data = $request->announcementAttributes();

        $this->assertPlacementIsReachable($request, $data);

        $announcement = $this->announcements->create($data, $request->user());

        return redirect()->route('announcements.show', $announcement)
            ->with('status', $announcement->displayName().' has been drafted. Publish it when it is ready.');
    }

    public function edit(Request $request, Announcement $announcement): View
    {
        $this->authorize('update', $announcement);

        return view('announcements.edit', [
            'announcement' => $announcement,
            ...$this->formOptions($request, $announcement),
        ]);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $this->authorize('update', $announcement);

        $data = $request->announcementAttributes();

        $this->assertPlacementIsReachable($request, $data);

        // Re-aiming a live notice changes who it is broadcast to, which is a publishing decision
        // rather than a correction. Somebody who may fix a typo may not redirect the notice at a
        // department that never agreed to receive it.
        if ($announcement->isPublished() && $this->addressingChanged($announcement, $data)) {
            $this->authorize('readdress', $announcement);
        }

        $this->announcements->update($announcement, $data, $request->user());

        return redirect()->route('announcements.show', $announcement)
            ->with('status', $announcement->displayName().' has been updated.');
    }

    /**
     * Publish, unpublish or archive.
     *
     * All three are the same permission — `announcement.publish` — because all three are the act of
     * deciding what the company is currently being told, and the route middleware gates them as one.
     * They are three different POLICY abilities, though, and asking the wrong one is not a stricter
     * check but a broken one: `publish()` means "put this DRAFT on the board" and answers false for
     * every notice already on it, so authorizing it here would refuse unpublishing and archiving to
     * everybody, at every scope, with a 403 that looks like a permissions problem and is not.
     *
     * Which act this is comes from the record's state and the target status, never from the request.
     */
    public function transition(Request $request, Announcement $announcement): RedirectResponse
    {
        $validated = $request->validate([
            'status_id' => ['required', 'integer'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $to = Status::query()->forEntity('announcement')->whereKey($validated['status_id'])->first();

        if ($to === null) {
            return back()->withErrors(['status_id' => 'That is not a status an announcement can be in.']);
        }

        $initial = $this->statuses->initialFor('announcement');

        $ability = match (true) {
            $announcement->isDraft() => 'publish',
            $initial !== null && (int) $to->getKey() === (int) $initial->getKey() => 'unpublish',
            default => 'archive',
        };

        // Asked again with the same actor who was shown the buttons (§84).
        $this->authorize($ability, $announcement);

        try {
            $this->announcements->transition($announcement, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\DomainException $e) {
            return back()->withErrors(['status_id' => $e->getMessage()]);
        }

        return redirect()->route('announcements.show', $announcement)
            ->with('status', $announcement->displayName().' is now '.$to->label.'.');
    }

    public function destroy(Request $request, Announcement $announcement): RedirectResponse
    {
        $this->authorize('delete', $announcement);

        $name = $announcement->displayName();

        $this->activity->record(
            $announcement,
            'deleted',
            $request->user()->name.' deleted '.$name,
            [],
            $request->user(),
            $announcement->project_id !== null ? (int) $announcement->project_id : null,
        );

        $announcement->delete();

        return redirect()->route('announcements.index')->with('status', $name.' has been deleted.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string,mixed>  $data
     */
    private function addressingChanged(Announcement $announcement, array $data): bool
    {
        $before = [(int) ($announcement->project_id ?? 0), (int) ($announcement->org_unit_id ?? 0)];
        $after = [(int) ($data['project_id'] ?? 0), (int) ($data['org_unit_id'] ?? 0)];

        return $before !== $after;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function assertPlacementIsReachable(Request $request, array $data): void
    {
        // Addressing a notice at a project or department is not the same as being able to see one
        // there — but pinning a notice to a unit you cannot reach would broadcast into a part of the
        // company you have no standing in, so it is refused on the same test (§84).
        if (($projectId = $data['project_id'] ?? null) !== null) {
            $this->authorize('view', Project::findOrFail($projectId));
        }

        if (($unitId = $data['org_unit_id'] ?? null) !== null) {
            $this->authorize('view', OrgUnit::findOrFail($unitId));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function formOptions(Request $request, ?Announcement $announcement): array
    {
        return [
            'projects' => $this->projectOptions($request),
            'units' => $this->unitOptions($request),
            'urgencies' => AnnouncementRequest::urgencyOptions(),
            'nextNumber' => $announcement === null ? $this->numbers->preview('announcement') : null,
        ];
    }

    /**
     * @return array<int|string,string>
     */
    private function projectOptions(Request $request): array
    {
        return $this->compiler
            ->apply(Project::query()->active(), $request->user(), 'project.view')
            ->orderBy('name')->limit(500)->get()
            ->mapWithKeys(fn (Project $p) => [$p->id => $p->displayName()])
            ->all();
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

    /**
     * @return array<string,string>
     */
    private function viewOptions(): array
    {
        return [
            'current' => 'For me',
            'live' => 'All live notices',
            'mine' => 'Written by me',
            'drafts' => 'Drafts',
            'all' => 'Everything you can see',
        ];
    }
}
