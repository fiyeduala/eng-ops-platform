<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageCondition;
use App\Support\Enums\AssigneeType;
use App\Support\Enums\StageAction;
use App\Support\WorkflowCatalogue;
use Illuminate\Validation\Rule;

/**
 * One step of an approval route, with its conditions.
 *
 * THE ASSIGNEE REFERENCE IS THE INTERESTING FIELD. `assignee_ref` is a single string column that
 * means a different thing for each assignee type — a role id, an org unit id, a user id, a field
 * name on the record. That is the right shape for storage (one column, one lookup) and exactly the
 * wrong shape to validate with a static rule, so the check is conditional on the type: a
 * `user`-typed stage must name a user IN THIS TENANT, a `role`-typed stage a role that exists here,
 * and a `record_field` stage a field the target model actually exposes.
 *
 * Getting that wrong is not a security hole — AssigneeResolver resolves within the tenant regardless
 * and an unresolvable reference stalls rather than escalating — but it is the difference between an
 * administrator learning at the form that they picked a deleted role, and a document sitting
 * unnoticed for a week because the stage it entered resolves to nobody.
 *
 * CONDITION FIELDS are checked against the module's own whitelist, not against its columns. See
 * WorkflowCatalogue for why that distinction carries a §61 argument.
 */
class WorkflowStageRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:2000'],

            'assignee_type' => ['required', Rule::enum(AssigneeType::class)],
            'assignee_ref' => $this->assigneeRefRules(),

            'action_type' => ['required', Rule::enum(StageAction::class)],

            'quorum' => ['required', Rule::in(array_keys(WorkflowCatalogue::quorums()))],
            'quorum_count' => [
                Rule::requiredIf(fn () => $this->input('quorum') === WorkflowStage::QUORUM_N_OF_M),
                'nullable', 'integer', 'min:1', 'max:50',
            ],

            // Hours, not days: an approval that must happen the same afternoon is a real
            // requirement, and expressing it in days would round it away.
            'sla_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],

            'on_reject' => ['required', Rule::in([
                WorkflowStage::ON_REJECT_TERMINATE,
                WorkflowStage::ON_REJECT_PREVIOUS,
                WorkflowStage::ON_REJECT_OWNER,
            ])],

            'allow_delegate' => ['boolean'],
            'allow_self_approval' => ['boolean'],
            'is_active' => ['boolean'],
            'sequence' => ['nullable', 'integer', 'min:1', 'max:200'],

            // --- conditions ---------------------------------------------------------------
            'conditions' => ['nullable', 'array', 'max:20'],
            'conditions.*.field' => ['required_with:conditions', 'string', Rule::in($this->conditionFields())],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(array_keys(WorkflowStageCondition::OPERATORS))],
            'conditions.*.value' => ['nullable', 'string', 'max:500'],
            'conditions.*.logic' => ['nullable', Rule::in(['and', 'or'])],

            // --- auto-actions -------------------------------------------------------------
            // The keys here are the ones AutoActionRunner actually reads. A generic "value" that
            // the runner then has to guess the meaning of is how a builder ends up storing
            // configuration nothing consumes — the stage saves, the screen says it saved, and the
            // action silently never happens.
            'auto_actions' => ['nullable', 'array', 'max:10'],
            'auto_actions.*.verb' => ['required_with:auto_actions', Rule::in(array_keys(WorkflowCatalogue::autoActions()))],
            'auto_actions.*.status' => ['nullable', 'string', 'max:100'],
            'auto_actions.*.title' => ['nullable', 'string', 'max:200'],
            'auto_actions.*.message' => ['nullable', 'string', 'max:500'],
            'auto_actions.*.due_in_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'auto_actions.*.assignee_type' => ['nullable', Rule::enum(AssigneeType::class)],
            // Deliberately looser than the stage's own reference rule: an auto-action's audience is
            // resolved through the same tenant-bound resolver, so a stale id resolves to nobody
            // rather than to somebody else's staff. Refusing to save the stage over it would block
            // an approval route on a notification setting.
            'auto_actions.*.assignee_ref' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * What `assignee_ref` has to be, given what kind of assignee this stage names.
     *
     * @return array<int,mixed>
     */
    private function assigneeRefRules(): array
    {
        $type = AssigneeType::tryFrom((string) $this->input('assignee_type'));

        if ($type === null || ! $type->needsReference()) {
            // Derived types — the record's owner, the submitter's supervisor, the project manager —
            // carry no reference at all. Accepting one would store a value the engine never reads,
            // which is worse than rejecting it: the builder would show a setting that does nothing.
            return ['nullable', 'string', 'max:150'];
        }

        return match ($type) {
            AssigneeType::User => ['required', 'integer', $this->existsInTenant('users', 'id', true)],
            // IDS, not slugs. AssigneeResolver looks both of these up with whereKey()/where('id'),
            // so validating a slug here would accept a configuration that saves cleanly, passes
            // every check on this screen, and then resolves to nobody the first time a real
            // document travels the route.
            AssigneeType::Role => ['required', 'integer', $this->existsInTenant('roles', 'id', true)],
            AssigneeType::OrgUnit,
            AssigneeType::OrgUnitHead,
            AssigneeType::ParentUnitHead => ['required', 'integer', $this->existsInTenant('org_units')],
            AssigneeType::ProjectRole => ['required', 'integer', $this->existsInTenant('project_roles', 'id', true)],
            AssigneeType::RecordField => ['required', 'string', 'max:150'],
            default => ['required', 'string', 'max:150'],
        };
    }

    /**
     * The condition fields this route's module exposes.
     *
     * Resolved from the parent workflow rather than from the request, so a posted `entity_type`
     * cannot widen the whitelist (§84).
     *
     * @return array<int,string>
     */
    private function conditionFields(): array
    {
        $workflow = $this->route('workflow') ?? $this->route('workflow_stage')?->workflow;

        if (! $workflow instanceof Workflow) {
            return [];
        }

        return array_keys(WorkflowCatalogue::conditionFields($workflow->entity_type));
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['allow_delegate', 'allow_self_approval', 'is_active']);
        $this->nullifyBlanks(['assignee_ref', 'instructions', 'sla_hours', 'quorum_count', 'sequence']);

        /*
         * REMOVING THE LAST AUTO-ACTION HAS TO BE POSSIBLE. A browser posts nothing at all for an
         * empty repeater, so without this an administrator can add an action and never take it
         * away — the field simply vanishes from the request and validated() leaves the stored JSON
         * alone. Normalising the absence to an empty array makes "none" a value the form can send.
         *
         * Rows whose verb was never chosen are dropped here rather than rejected: an empty row is
         * somebody who clicked "add" and changed their mind, not a mistake worth a red message.
         */
        $actions = $this->input('auto_actions');

        $this->merge([
            'auto_actions' => collect(is_array($actions) ? $actions : [])
                ->filter(fn ($row) => is_array($row) && filled($row['verb'] ?? null))
                ->map(fn (array $row) => array_filter(
                    $row,
                    fn ($value) => $value !== '' && $value !== null,
                ))
                ->values()
                ->all(),
        ]);
    }

    /** @return array<string,mixed> */
    public function stageAttributes(): array
    {
        $data = $this->validated();

        unset($data['conditions']);

        return $data;
    }

    /** @return array<int,array<string,mixed>> */
    public function conditions(): array
    {
        $conditions = $this->validated()['conditions'] ?? [];

        return is_array($conditions) ? array_values($conditions) : [];
    }
}
