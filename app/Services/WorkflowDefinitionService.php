<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageCondition;
use App\Support\Enums\AssigneeType;
use App\Support\Enums\StageAction;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * Building and publishing approval routes — the configuration side of the workflow engine.
 *
 * This is the class that makes §5 true: a company defines its own approval chains through the UI,
 * and no developer edits source code to add a stage. Everything the engine reads at runtime is
 * written here.
 *
 * THE VERSIONING RULE, which is the whole design:
 *
 *   A DRAFT workflow can be edited freely. It has never routed anything.
 *   A PUBLISHED workflow with no live instances can still be edited in place.
 *   A PUBLISHED workflow WITH live instances is frozen. Changing it creates version n+1.
 *
 * The third case is the one that matters. A document halfway through a four-stage route, whose
 * definition is edited to have three stages, is a record whose history no longer describes a route
 * that exists — and the engine, walking `sequence > current`, would either skip a stage or fall off
 * the end. Versioning means in-flight records keep the route they entered, and new records get the
 * new one. Both are correct at the same time, which is the only acceptable answer.
 *
 * PUBLISHING VALIDATES, and refuses. A route with no stages, or a stage whose assignee reference is
 * missing, will stall the first record that enters it. Catching that at publish time costs an
 * administrator one error message; catching it at runtime costs somebody a stalled document and a
 * support call.
 */
class WorkflowDefinitionService
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    // ================================================================== definitions

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes, User $actor): Workflow
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $workflow = new Workflow($this->safeAttributes($attributes));

            $workflow->organization_id = $actor->organization_id;
            $workflow->created_by = $actor->getKey();
            $workflow->version = 1;
            $workflow->is_active = true;
            $workflow->published_at = null;   // always born a draft

            $workflow->save();

            $this->activity->record(
                $workflow,
                'created',
                $actor->name.' created the approval route "'.$workflow->name.'".',
                ['entity_type' => $workflow->entity_type],
                $actor,
            );

            return $workflow;
        });
    }

    /**
     * Edit a definition, or fork it if editing would disturb records in flight.
     *
     * Returns whichever workflow the caller should now be looking at — the same one, or the new
     * version. The caller redirects to it rather than assuming.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Workflow $workflow, array $attributes, User $actor): Workflow
    {
        if (! $workflow->isEditableInPlace()) {
            return $this->newVersion($workflow, $attributes, $actor);
        }

        $workflow->fill($this->safeAttributes($attributes))->save();

        return $workflow;
    }

    /**
     * Fork a frozen definition into version n+1, stages, conditions and all.
     *
     * The old version stays published and active until the new one is published, so there is no
     * moment where a module has no route. `supersedes_id` records the lineage, which is what lets a
     * reader of a two-year-old approval history find the definition it actually followed.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function newVersion(Workflow $workflow, array $attributes, User $actor): Workflow
    {
        return DB::transaction(function () use ($workflow, $attributes, $actor) {
            $next = new Workflow($this->safeAttributes($attributes));

            $next->organization_id = $workflow->organization_id;
            $next->name = $attributes['name'] ?? $workflow->name;
            $next->entity_type = $workflow->entity_type;
            $next->trigger = $workflow->trigger;
            $next->description = $attributes['description'] ?? $workflow->description;
            $next->version = (int) Workflow::query()
                ->where('entity_type', $workflow->entity_type)
                ->where('name', $workflow->name)
                ->max('version') + 1;
            $next->supersedes_id = $workflow->getKey();
            $next->is_default = false;   // claimed at publish time, not inherited silently
            $next->is_active = true;
            $next->published_at = null;
            $next->created_by = $actor->getKey();

            $next->save();

            foreach ($workflow->stages()->with('conditions')->orderBy('sequence')->get() as $stage) {
                $copy = $stage->replicate(['id', 'workflow_id', 'created_at', 'updated_at']);
                $copy->workflow_id = $next->getKey();
                $copy->save();

                foreach ($stage->conditions as $condition) {
                    $conditionCopy = $condition->replicate(['id', 'workflow_stage_id', 'created_at', 'updated_at']);
                    $conditionCopy->workflow_stage_id = $copy->getKey();
                    $conditionCopy->save();
                }
            }

            $this->activity->record(
                $next,
                'versioned',
                $actor->name.' created version '.$next->version.' of "'.$next->name.'", because version '
                    .$workflow->version.' has records in progress.',
                ['supersedes' => $workflow->getKey(), 'version' => $next->version],
                $actor,
            );

            return $next;
        });
    }

    // ================================================================== stages

    /** @param array<string,mixed> $attributes */
    public function addStage(Workflow $workflow, array $attributes, User $actor): WorkflowStage
    {
        return DB::transaction(function () use ($workflow, $attributes) {
            $stage = new WorkflowStage($this->safeStageAttributes($attributes));

            $stage->organization_id = $workflow->organization_id;
            $stage->workflow_id = $workflow->getKey();
            $stage->sequence = (int) ($attributes['sequence'] ?? ((int) $workflow->stages()->max('sequence') + 1));

            $stage->save();

            return $stage;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function updateStage(WorkflowStage $stage, array $attributes, User $actor): WorkflowStage
    {
        $stage->fill($this->safeStageAttributes($attributes))->save();

        return $stage;
    }

    public function deleteStage(WorkflowStage $stage, User $actor): void
    {
        DB::transaction(function () use ($stage) {
            $workflow = $stage->workflow;
            $sequence = (int) $stage->sequence;

            $stage->conditions()->delete();
            $stage->delete();

            // Close the gap, so the sequences stay 1..n. The engine walks `sequence > current` and
            // works fine with holes, but an administrator reading "1, 2, 4, 5" reasonably wonders
            // what happened to 3 and whether it is hidden rather than gone.
            $workflow->stages()
                ->where('sequence', '>', $sequence)
                ->orderBy('sequence')
                ->get()
                ->each(fn (WorkflowStage $later) => $later->forceFill(['sequence' => $later->sequence - 1])->save());
        });
    }

    /**
     * Reorder stages in one go, from the builder's drag-and-drop.
     *
     * Two passes with an offset in between, because `sequence` is uniquely indexed per workflow and
     * a single-pass renumber collides the moment two stages swap places.
     *
     * @param  array<int,int>  $orderedStageIds
     */
    public function reorderStages(Workflow $workflow, array $orderedStageIds, User $actor): void
    {
        DB::transaction(function () use ($workflow, $orderedStageIds) {
            $stages = $workflow->stages()->get()->keyBy('id');

            foreach (array_values($orderedStageIds) as $index => $id) {
                $stage = $stages->get((int) $id);

                if ($stage !== null) {
                    $stage->forceFill(['sequence' => 1000 + $index])->save();
                }
            }

            foreach (array_values($orderedStageIds) as $index => $id) {
                $stage = $stages->get((int) $id);

                if ($stage !== null) {
                    $stage->forceFill(['sequence' => $index + 1])->save();
                }
            }
        });
    }

    // ================================================================== conditions

    /** @param array<string,mixed> $attributes */
    public function addCondition(WorkflowStage $stage, array $attributes, User $actor): WorkflowStageCondition
    {
        $condition = new WorkflowStageCondition([
            'field' => $attributes['field'],
            'operator' => $attributes['operator'] ?? '=',
            'value' => $attributes['value'] ?? null,
            'logic' => in_array($attributes['logic'] ?? 'and', ['and', 'or'], true) ? $attributes['logic'] : 'and',
            'sort' => (int) ($attributes['sort'] ?? ((int) $stage->conditions()->max('sort') + 1)),
        ]);

        $condition->organization_id = $stage->organization_id;
        $condition->workflow_stage_id = $stage->getKey();
        $condition->save();

        return $condition;
    }

    // ================================================================== publishing

    /**
     * Make a route live, after checking it will actually work.
     *
     * @throws \DomainException listing every problem at once, because fixing them one error message
     *                          at a time is how a five-stage route takes an afternoon to publish
     */
    public function publish(Workflow $workflow, User $actor): Workflow
    {
        $problems = $this->validate($workflow);

        if ($problems !== []) {
            throw new \DomainException(
                'This route cannot be published yet: '.implode(' ', $problems)
            );
        }

        return DB::transaction(function () use ($workflow, $actor) {
            $workflow->forceFill([
                'published_at' => now(),
                'published_by' => $actor->getKey(),
                'is_active' => true,
            ])->save();

            if ($workflow->is_default) {
                $this->claimDefault($workflow);
            }

            // The version this one forked from stops taking new records, but keeps running the ones
            // it already has: deactivated, never deleted. An in-flight instance still needs its
            // definition to read the stage names and rejection behaviour from.
            if ($workflow->supersedes_id !== null) {
                Workflow::query()
                    ->whereKey($workflow->supersedes_id)
                    ->update(['is_active' => false, 'is_default' => false, 'updated_at' => now()]);
            }

            $this->activity->record(
                $workflow,
                'published',
                $actor->name.' published "'.$workflow->name.'" version '.$workflow->version.'.',
                ['version' => $workflow->version, 'stages' => $workflow->stages()->count()],
                $actor,
            );

            return $workflow;
        });
    }

    /**
     * Everything wrong with a definition, in plain words.
     *
     * Public because the builder shows it live as a checklist, so an administrator sees what is
     * still missing before pressing anything.
     *
     * @return array<int,string>
     */
    public function validate(Workflow $workflow): array
    {
        $problems = [];
        $stages = $workflow->stages()->orderBy('sequence')->get();

        if ($stages->isEmpty()) {
            $problems[] = 'It has no stages.';
        }

        if ($stages->where('is_active', true)->isEmpty() && $stages->isNotEmpty()) {
            $problems[] = 'None of its stages are active.';
        }

        foreach ($stages as $stage) {
            $type = $stage->assignee_type;

            if (! $type instanceof AssigneeType) {
                $problems[] = 'Stage '.$stage->sequence.' has no valid assignee type.';

                continue;
            }

            if ($type->needsReference() && blank($stage->assignee_ref)) {
                $problems[] = 'Stage '.$stage->sequence.' ("'.$stage->name.'") is set to '
                    .$type->label().' but names nobody.';
            }

            if ($type->requiresProject() && ! $this->entityHasProjects($workflow->entity_type)) {
                $problems[] = 'Stage '.$stage->sequence.' routes to somebody on the project, but '
                    .$workflow->entity_type.' records are not attached to a project.';
            }

            if ($stage->quorum === WorkflowStage::QUORUM_N_OF_M && (int) $stage->quorum_count < 1) {
                $problems[] = 'Stage '.$stage->sequence.' asks for "N of M" approvals but N is not set.';
            }

            if (! $stage->action_type instanceof StageAction) {
                $problems[] = 'Stage '.$stage->sequence.' has no valid action.';
            }
        }

        return $problems;
    }

    /**
     * Can records of this kind belong to a project at all?
     *
     * Asked of the model itself through the access engine's own hook, so the answer stays true when
     * a module changes its mind about projects. A stage set to "the project manager" on a module
     * with no project column resolves to nobody every time — a stall waiting to happen, and exactly
     * the class of mistake publishing is supposed to catch.
     */
    private function entityHasProjects(string $entityType): bool
    {
        $class = Relation::getMorphedModel($entityType);

        if ($class === null || ! class_exists($class)) {
            return true;   // unknown module: do not block a publish on our own ignorance
        }

        $model = new $class;

        return method_exists($model, 'accessProjectColumn')
            ? $model->accessProjectColumn() !== null
            : false;
    }

    /** Only one default per module, and claiming it takes it off whoever had it. */
    private function claimDefault(Workflow $workflow): void
    {
        Workflow::query()
            ->where('entity_type', $workflow->entity_type)
            ->where('id', '!=', $workflow->getKey())
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    public function deactivate(Workflow $workflow, User $actor): Workflow
    {
        $workflow->forceFill(['is_active' => false, 'is_default' => false])->save();

        $this->activity->record(
            $workflow,
            'deactivated',
            $actor->name.' took "'.$workflow->name.'" out of use. Records already in progress will finish on it.',
            [],
            $actor,
        );

        return $workflow;
    }

    // ================================================================== internals

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function safeAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'organization_id', 'version', 'supersedes_id', 'published_at', 'published_by',
            'created_by', 'created_at', 'updated_at', 'deleted_at',
        ]));
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function safeStageAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'organization_id', 'workflow_id', 'created_at', 'updated_at',
        ]));
    }
}
