<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Audit\ActivityRecorder;
use App\Http\Requests\WorkflowRequest;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Services\WorkflowDefinitionService;
use App\Support\WorkflowCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The workflow builder (§5, §33) — the screen Phase 3's exit criteria are written about.
 *
 * A company defines an approval route here, entirely through the UI, and documents start travelling
 * it. No developer edits a class, no deployment happens, and nothing in the engine knows what a
 * document is. That is the promise the whole platform is built around, and this is where it is kept.
 *
 * VERSIONING IS THE HARD PART, and it is a service decision rather than a controller one. A DRAFT
 * route is edited in place. A PUBLISHED route with instances in flight is FROZEN: editing it forks
 * version n+1 and leaves the running approvals alone. The alternative — editing a live definition —
 * means a document that entered a three-stage route finishes a five-stage one, and the approval
 * record no longer describes what actually happened (§59). WorkflowDefinitionService::update()
 * decides which of the two occurred; this controller reports which, because a user who pressed
 * "Save" and got a new version needs to be told that is what happened.
 *
 * PUBLISHING IS VALIDATED, NOT JUST PERMITTED. validate() returns every problem at once — a stage
 * that resolves to nobody, a route with no stages, a project-scoped assignee on a module with no
 * projects — and publish() refuses until they are all fixed. A route that publishes cleanly and
 * then stalls on its first document is the failure mode this prevents, and it is worse than a form
 * error because it is discovered days later by somebody who did not configure it.
 */
class WorkflowController extends Controller
{
    public function __construct(
        private readonly ScopeCompiler $compiler,
        private readonly ActivityRecorder $activity,
        private readonly WorkflowDefinitionService $definitions,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Workflow::class);

        $user = $request->user();

        $query = $this->compiler->apply(Workflow::query(), $user, 'workflow.view')
            ->with(['creator:id,name', 'publisher:id,name'])
            ->withCount(['stages', 'instances']);

        if ($entity = (string) $request->query('entity', '')) {
            $query->forEntity($entity);
        }

        $view = (string) $request->query('view', 'current');

        match ($view) {
            'drafts' => $query->where('is_active', true)->whereNull('published_at'),
            'published' => $query->whereNotNull('published_at')->where('is_active', true),
            'superseded' => $query->where('is_active', false),
            'all' => $query,
            default => $query->where('is_active', true),
        };

        return view('workflows.index', [
            'workflows' => $query->orderBy('entity_type')->orderBy('name')->orderByDesc('version')
                ->paginate(25)->withQueryString(),
            'entities' => WorkflowCatalogue::routableEntities(),
            'views' => [
                'current' => 'In use',
                'drafts' => 'Drafts',
                'published' => 'Published',
                'superseded' => 'Superseded',
                'all' => 'Everything',
            ],
            'filters' => ['entity' => $request->query('entity'), 'view' => $view],
            'canCreate' => $user->can('create', Workflow::class),
        ]);
    }

    /**
     * The builder itself: the route, its stages, and a live checklist of what is stopping it
     * publishing.
     *
     * The checklist is shown while the route is still a draft rather than as an error after
     * pressing Publish, because the person configuring it is usually not the person who will
     * discover it is broken.
     */
    public function show(Request $request, Workflow $workflow): View
    {
        $this->authorize('view', $workflow);

        $user = $request->user();

        $workflow->loadMissing([
            'stages' => fn ($q) => $q->orderBy('sequence')->with('conditions'),
            'creator:id,name',
            'publisher:id,name',
            'supersedes:id,name,version',
        ]);

        return view('workflows.show', [
            'workflow' => $workflow,
            'stages' => $workflow->stages,
            'problems' => $this->definitions->validate($workflow),
            'conditionFields' => WorkflowCatalogue::conditionFields($workflow->entity_type),
            'assigneeTypes' => WorkflowCatalogue::assigneeTypes(),
            'stageActions' => WorkflowCatalogue::stageActions(),
            'quorums' => WorkflowCatalogue::quorums(),
            'operators' => WorkflowCatalogue::operators(),
            'autoActions' => WorkflowCatalogue::autoActions(),
            'timeline' => $this->activity->timelineFor($workflow)->limit(20)->get(),
            'canEdit' => $user->can('update', $workflow),
            'canPublish' => $user->can('publish', $workflow),
            'canVersion' => $user->can('version', $workflow),
            'canDeactivate' => $user->can('deactivate', $workflow),
            'canDelete' => $user->can('delete', $workflow),
            'canAddStage' => $user->can('createFor', [WorkflowStage::class, $workflow]),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Workflow::class);

        return view('workflows.create', [
            'workflow' => new Workflow(['entity_type' => (string) $request->query('entity', '')]),
            'entities' => WorkflowCatalogue::routableEntities(),
        ]);
    }

    public function store(WorkflowRequest $request): RedirectResponse
    {
        $this->authorize('create', Workflow::class);

        $workflow = $this->definitions->create($request->workflowAttributes(), $request->user());

        return redirect()->route('workflows.show', $workflow)
            ->with('status', $workflow->name.' has been created as a draft. Add its stages, then publish it.');
    }

    public function edit(Request $request, Workflow $workflow): View
    {
        $this->authorize('update', $workflow);

        return view('workflows.edit', [
            'workflow' => $workflow,
            'entities' => WorkflowCatalogue::routableEntities(),

            // Shown on the form, because pressing Save on a published route is not an edit — it is
            // the creation of version n+1, and that is a decision the user should make knowingly.
            'willFork' => ! $workflow->isEditableInPlace(),
        ]);
    }

    public function update(WorkflowRequest $request, Workflow $workflow): RedirectResponse
    {
        $this->authorize('update', $workflow);

        $before = (int) $workflow->version;

        $result = $this->definitions->update($workflow, $request->workflowAttributes(), $request->user());

        // The service, not this controller, decided whether that was an edit or a fork. Reporting
        // it truthfully matters: a user who thinks they edited a live route, and actually created
        // an unpublished version 2, would wonder why nothing changed.
        $forked = (int) $result->version !== $before;

        return redirect()->route('workflows.show', $result)->with(
            'status',
            $forked
                ? $result->name.' was already in use, so version '.$result->version
                    .' has been created as a draft. Publish it when it is ready.'
                : $result->name.' has been updated.'
        );
    }

    /**
     * Make a route live.
     *
     * Publishing claims the default for its module if asked, deactivates the version it supersedes,
     * and from that moment new records enter this route. Every reason it cannot happen is returned
     * at once rather than one per attempt.
     */
    public function publish(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorize('publish', $workflow);

        try {
            $this->definitions->publish($workflow, $request->user());
        } catch (\DomainException $e) {
            return back()->withErrors(['publish' => $e->getMessage()]);
        }

        return back()->with('status', $workflow->name.' is now live. New records will take this route.');
    }

    /**
     * Fork a new draft version explicitly.
     *
     * The same thing update() does implicitly when a live route is edited, offered as its own button
     * so an administrator can start work on version 3 without first pretending to edit version 2.
     */
    public function version(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorize('version', $workflow);

        $next = $this->definitions->newVersion($workflow, [], $request->user());

        return redirect()->route('workflows.show', $next)
            ->with('status', 'Version '.$next->version.' has been created as a draft. Version '
                .$workflow->version.' stays live until you publish it.');
    }

    /**
     * Take a route out of use.
     *
     * Deliberately not a delete. Records that travelled this route point at it, and their approval
     * history is only readable while the definition it was written against still exists (§59).
     * Deactivating stops new records entering; nothing in flight is disturbed.
     */
    public function deactivate(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorize('deactivate', $workflow);

        $this->definitions->deactivate($workflow, $request->user());

        return back()->with('status', $workflow->name.' is no longer in use. Approvals already running are unaffected.');
    }

    public function destroy(Request $request, Workflow $workflow): RedirectResponse
    {
        $this->authorize('delete', $workflow);

        $name = $workflow->name;

        $this->activity->record(
            $workflow,
            'deleted',
            $request->user()->name.' deleted the draft route '.$name.'.',
            [],
            $request->user(),
        );

        $workflow->delete();

        return redirect()->route('workflows.index')->with('status', $name.' has been deleted.');
    }
}
