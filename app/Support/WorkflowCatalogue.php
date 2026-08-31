<?php

declare(strict_types=1);

namespace App\Support;

use App\Engines\Workflow\AutoActionRunner;
use App\Engines\Workflow\Concerns\HasWorkflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageCondition;
use App\Support\Enums\AssigneeType;
use App\Support\Enums\StageAction;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * What the workflow builder is allowed to offer, in one place.
 *
 * The builder is a screen where an administrator chooses a module, a stage action, an assignee rule
 * and a set of conditions. Every one of those choices ends up in the database and is later read back
 * by the engine, so every one of them needs a closed list — a route pointed at a made-up morph key
 * or a condition field naming a column that does not exist are both configuration that fails at
 * runtime, on somebody's document, days later.
 *
 * ROUTABLE ENTITIES ARE DISCOVERED, NOT LISTED. The answer is "every model in the morph map that
 * uses HasWorkflow", which means adding approvals to a new module in a later phase requires adding
 * a trait and nothing else — no edit here, no forgotten registration, no module that silently
 * cannot be routed because somebody missed a list (§78, §90).
 *
 * CONDITION FIELDS COME FROM THE MODEL, through `workflowConditionFields()`. That is deliberately
 * the model's decision rather than a reflection over its columns: Document excludes revision
 * content from the list because a route that could branch on the words inside a confidential
 * document would be readable back out of the approval history (§61).
 */
class WorkflowCatalogue
{
    /**
     * Modules that can be routed for approval: morph key => human label.
     *
     * @return array<string,string>
     */
    public static function routableEntities(): array
    {
        $entities = [];

        foreach (Relation::morphMap() as $key => $class) {
            if (! class_exists($class)) {
                continue;
            }

            if (! in_array(HasWorkflow::class, class_uses_recursive($class), true)) {
                continue;
            }

            $entities[$key] = str(class_basename($class))->headline()->plural()->toString();
        }

        ksort($entities);

        return $entities;
    }

    /**
     * The fields a stage condition may test, for one module.
     *
     * @return array<string,string> field => label
     */
    public static function conditionFields(string $entityType): array
    {
        $class = Relation::getMorphedModel($entityType);

        if ($class === null || ! class_exists($class)) {
            return [];
        }

        $model = new $class;

        if (! method_exists($model, 'workflowConditionFields')) {
            return [];
        }

        $fields = [];

        foreach ($model->workflowConditionFields() as $field) {
            $fields[$field] = str(str_replace(['.', '_id', '_'], [' → ', '', ' '], $field))
                ->headline()
                ->toString();
        }

        return $fields;
    }

    /** @return array<string,string> */
    public static function operators(): array
    {
        return WorkflowStageCondition::OPERATORS;
    }

    /** @return array<string,string> */
    public static function assigneeTypes(): array
    {
        $types = [];

        foreach (AssigneeType::cases() as $case) {
            $types[$case->value] = $case->label();
        }

        return $types;
    }

    /** @return array<string,string> */
    public static function stageActions(): array
    {
        $actions = [];

        foreach (StageAction::cases() as $case) {
            $actions[$case->value] = $case->label();
        }

        return $actions;
    }

    /** @return array<string,string> */
    public static function quorums(): array
    {
        return WorkflowStage::quorumOptions();
    }

    /**
     * What a stage may do automatically when it opens.
     *
     * A short list on purpose. Auto-actions are the part of a workflow definition that reaches out
     * and changes other records, so every verb here is one whose blast radius is understood and
     * whose failure AutoActionRunner catches individually — a misconfigured action must never stop
     * a company's documents from routing.
     *
     * @return array<string,string>
     */
    public static function autoActions(): array
    {
        return AutoActionRunner::VERBS;
    }
}
