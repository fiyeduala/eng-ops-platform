<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Access\ScopeCompiler;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Workflow\AssigneeResolver;
use App\Http\Requests\WorkflowStageRequest;
use App\Models\OrgUnit;
use App\Models\ProjectRole;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Services\WorkflowDefinitionService;
use App\Support\WorkflowCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Stages: the steps of a route, and their conditions.
 *
 * Nested under a workflow because a stage has no meaning without one — its sequence is unique per
 * workflow, its conditions are evaluated against that workflow's entity type, and its assignee rules
 * are validated against that entity's shape. Every method asserts the nesting rather than trusting
 * it: `/workflows/1/stages/44` where stage 44 belongs to workflow 2 is a 404, which is §94's IDOR
 * case for this module.
 *
 * A STAGE MAY ONLY BE EDITED WHILE ITS ROUTE IS A DRAFT. WorkflowStagePolicy delegates to the
 * parent, so this is one rule in one place rather than a repeated check — and the reason is the same
 * as everywhere else in this module: a document that entered a three-stage route must finish the
 * route it entered (§59). Editing a live route forks a version; that happens in WorkflowController,
 * and the builder sends the user there rather than silently rewriting history underneath running
 * approvals.
 *
 * REORDERING IS A SEPARATE ENDPOINT because it is a separate authority and a separate problem. The
 * `sequence` column is uniquely indexed per workflow, so swapping two stages collides mid-update;
 * the service renumbers in two passes through a high offset. Doing that in a general `update` would
 * mean every stage edit paid for a problem only reordering has.
 */
class WorkflowStageController extends Controller
{
    public function __construct(private readonly WorkflowDefinitionService $definitions) {}

    public function create(Request $request, Workflow $workflow): View
    {
        $this->authorize('createFor', [WorkflowStage::class, $workflow]);

        return view('workflows.stages.create', [
            'workflow' => $workflow,
            'stage' => new WorkflowStage([
                'quorum' => WorkflowStage::QUORUM_ANY,
                'on_reject' => WorkflowStage::ON_REJECT_OWNER,
                'is_active' => true,
            ]),
            ...$this->builderOptions($workflow),
        ]);
    }

    public function store(WorkflowStageRequest $request, Workflow $workflow): RedirectResponse
    {
        $this->authorize('createFor', [WorkflowStage::class, $workflow]);

        $stage = $this->definitions->addStage($workflow, $request->stageAttributes(), $request->user());

        $this->syncConditions($stage, $request);

        return redirect()->route('workflows.show', $workflow)
            ->with('status', 'Stage "'.$stage->name.'" has been added.');
    }

    public function edit(Request $request, Workflow $workflow, WorkflowStage $stage): View
    {
        $this->assertBelongs($workflow, $stage);

        $this->authorize('update', $stage);

        $stage->loadMissing('conditions');

        return view('workflows.stages.edit', [
            'workflow' => $workflow,
            'stage' => $stage,
            ...$this->builderOptions($workflow),
        ]);
    }

    public function update(WorkflowStageRequest $request, Workflow $workflow, WorkflowStage $stage): RedirectResponse
    {
        $this->assertBelongs($workflow, $stage);

        $this->authorize('update', $stage);

        $this->definitions->updateStage($stage, $request->stageAttributes(), $request->user());

        $this->syncConditions($stage, $request);

        return redirect()->route('workflows.show', $workflow)
            ->with('status', 'Stage "'.$stage->name.'" has been updated.');
    }

    public function destroy(Request $request, Workflow $workflow, WorkflowStage $stage): RedirectResponse
    {
        $this->assertBelongs($workflow, $stage);

        $this->authorize('delete', $stage);

        $name = $stage->name;

        $this->definitions->deleteStage($stage, $request->user());

        return redirect()->route('workflows.show', $workflow)
            ->with('status', 'Stage "'.$name.'" has been removed and the sequence closed up.');
    }

    /**
     * Drag-and-drop reordering.
     *
     * Answers JSON because the builder reorders without a page load, and takes the whole ordered
     * list rather than a from/to pair — the service then renumbers the sequence in one operation.
     * Stage ids that do not belong to this workflow are refused outright rather than skipped: a
     * partial reorder is a route in an order nobody chose.
     */
    public function reorder(Request $request, Workflow $workflow): JsonResponse
    {
        $this->authorize('update', $workflow);

        $validated = $request->validate([
            'stages' => ['required', 'array', 'min:1', 'max:200'],
            'stages.*' => ['integer'],
        ]);

        $ids = array_map('intval', $validated['stages']);
        $own = $workflow->stages()->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (array_diff($ids, $own) !== [] || count($ids) !== count($own)) {
            return response()->json(
                ['message' => 'That is not the full list of this route\'s stages.'],
                422,
            );
        }

        $this->definitions->reorderStages($workflow, $ids, $request->user());

        return response()->json(['message' => 'Order saved.']);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Replace a stage's conditions with what the form posted.
     *
     * Replace rather than merge: conditions are a set, the form shows all of them, and a merge
     * would make removing one impossible. The service validates each against the module's own
     * whitelist of condition fields.
     */
    private function syncConditions(WorkflowStage $stage, WorkflowStageRequest $request): void
    {
        $conditions = $request->conditions();

        if ($conditions === [] && ! $request->has('conditions')) {
            return;   // the form did not carry conditions at all — leave them alone
        }

        $stage->conditions()->delete();

        foreach ($conditions as $condition) {
            $this->definitions->addCondition($stage, $condition, $request->user());
        }
    }

    /**
     * The nesting check. See the class docblock, and §94.
     */
    private function assertBelongs(Workflow $workflow, WorkflowStage $stage): void
    {
        abort_unless((int) $stage->workflow_id === (int) $workflow->getKey(), 404);
    }

    /**
     * @return array<string,mixed>
     */
    private function builderOptions(Workflow $workflow): array
    {
        return [
            'assigneeTypes' => WorkflowCatalogue::assigneeTypes(),
            'stageActions' => WorkflowCatalogue::stageActions(),
            'quorums' => WorkflowCatalogue::quorums(),
            'operators' => WorkflowCatalogue::operators(),
            'autoActions' => WorkflowCatalogue::autoActions(),
            'conditionFields' => WorkflowCatalogue::conditionFields($workflow->entity_type),
            'rejectOptions' => [
                WorkflowStage::ON_REJECT_OWNER => 'Send it back to the author',
                WorkflowStage::ON_REJECT_PREVIOUS => 'Send it back to the previous stage',
                WorkflowStage::ON_REJECT_TERMINATE => 'Stop the approval entirely',
            ],
            'references' => $this->assigneeReferences(),

            /*
             * The statuses a "move the record to a status" action may name, for THIS module only.
             *
             * AutoActionRunner matches on `Status::key`, and a key that does not exist for the
             * entity type throws — caught, logged, and the action silently does nothing. Offering
             * the real list is the difference between that and an administrator typing "approved"
             * where the configured key is "issued".
             */
            'statuses' => app(StatusMachine::class)
                ->statusesFor($workflow->entity_type)
                ->pluck('name', 'key')
                ->all(),
        ];
    }

    /**
     * The lists behind the `assignee_ref` picker, one per assignee type that needs one.
     *
     * `assignee_ref` is a single string column meaning a different thing per type — a user id, a
     * role id, an org unit id, a project role id — which is the right shape for storage and an
     * impossible thing to type from memory. Without these the builder would ask an administrator
     * for "the reference", and the commonest outcome of that question is a route that resolves to
     * nobody and stalls a week later on somebody's document.
     *
     * The keys match the AssigneeType values, so the form picks its list by the selected type
     * rather than by a parallel mapping that can fall out of step.
     *
     * SCOPED, not just permitted. The user list goes through ScopeCompiler on `user.view` — holding
     * `workflow.manage` is authority over routes, not a licence to read a staff directory somebody
     * is otherwise not shown (§61). Roles, units and project roles are organisation-wide
     * configuration and are already behind the tenant scope.
     *
     * @return array<string,array<int|string,string>>
     */
    private function assigneeReferences(): array
    {
        $user = request()->user();

        $users = $user !== null && $user->hasPermission('user.view')
            ? app(ScopeCompiler::class)
                ->apply(User::query()->active(), $user, 'user.view')
                ->orderBy('name')->limit(500)->pluck('name', 'id')->all()
            : [];

        $custom = app(AssigneeResolver::class)->keys();

        return [
            'user' => $users,
            'role' => Role::query()->orderBy('name')->pluck('name', 'id')->all(),
            'org_unit' => OrgUnit::query()->orderBy('name')->pluck('name', 'id')->all(),
            'project_role' => ProjectRole::query()->orderBy('name')->pluck('name', 'id')->all(),
            'custom' => array_combine($custom, $custom),
        ];
    }
}
