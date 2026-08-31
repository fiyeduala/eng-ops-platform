<?php

declare(strict_types=1);

namespace App\Engines\Workflow\Concerns;

use App\Models\Workflow;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Makes a model routable through the ONE workflow engine (§78).
 *
 * Adding approvals to a module is this trait plus a row in `workflows`. There is deliberately no
 * second path: §78 names ten divergent, separately-buggy approval systems as the failure mode to
 * design against, and the only reliable defence is that adding your own is more work than using
 * this one.
 *
 * A model adopting the trait answers four questions, and every one of them has a working default:
 *
 *   - which module am I, for finding the right workflow definition
 *   - who owns me, for `record_owner` and `return_to_owner`
 *   - which fields may a CONDITION read
 *   - what happens when the route finishes
 *
 * The condition whitelist is the one that carries security weight. ConditionEvaluator refuses any
 * field a model has not named here, so a workflow builder cannot be talked into reading
 * `users.password` through a cleverly typed field path. The list is declared BY the model rather
 * than centrally, because the model is the thing that knows which of its columns are safe to expose
 * to configuration — and because a central list would inevitably be maintained by whoever adds a
 * module last.
 */
trait HasWorkflow
{
    /**
     * Every route this record has ever travelled, newest first.
     *
     * All of them, not just the live one: "we sent this round twice and it came back the first
     * time" is exactly the history the module exists to keep.
     */
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->latest('id');
    }

    /** The route currently in flight, or null when the record is not out for approval. */
    public function currentWorkflowInstance(): ?WorkflowInstance
    {
        return $this->workflowInstances()
            ->where('status', WorkflowInstance::STATUS_RUNNING)
            ->first();
    }

    public function isInWorkflow(): bool
    {
        return $this->currentWorkflowInstance() !== null;
    }

    /**
     * The module key used to find a workflow definition — `document`, `purchase_request`.
     *
     * Matches the permission-catalogue key on purpose: a company's administrators already think in
     * those terms, and two parallel vocabularies for "which module is this" would drift.
     */
    public function workflowEntityType(): string
    {
        return str(class_basename(static::class))->snake()->toString();
    }

    /**
     * Who the route returns to when somebody asks for changes.
     *
     * Falls back through owner then creator, because a record with neither is a record nobody can
     * be asked to fix — and an approval sent back to nobody stalls, which is the correct but
     * unhelpful outcome.
     */
    public function workflowOwnerId(): ?int
    {
        foreach (['owner_id', 'created_by', 'user_id'] as $column) {
            if (isset($this->attributes[$column]) && $this->attributes[$column] !== null) {
                return (int) $this->attributes[$column];
            }
        }

        return null;
    }

    /** The org unit a stage means by "the owning department". */
    public function workflowOrgUnitId(): ?int
    {
        $value = $this->attributes['org_unit_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** The project a stage means by "this record's project". */
    public function workflowProjectId(): ?int
    {
        $value = $this->attributes['project_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Fields a workflow CONDITION may read on this model.
     *
     * A whitelist, never a blacklist. Dotted paths reach one level into a named relation and no
     * further — `project.project_type_id` is allowed if listed, `owner.employee.salary` is not,
     * whatever anybody types into the builder.
     *
     * Overriding models should list business attributes only. Never list a hash, a token, a
     * remember_token, or anything on the users table.
     *
     * @return array<int,string>
     */
    public function workflowConditionFields(): array
    {
        return ['status_id', 'confidentiality', 'org_unit_id', 'project_id'];
    }

    /**
     * A human label for the record, used in approval inboxes and notifications.
     *
     * Deliberately routed through displayName() where the model has one, because the inbox shows
     * this text to people who may hold only `approval.act` — it must be an identifier, never a
     * summary of content they have not been cleared to read (§68).
     */
    public function workflowLabel(): string
    {
        return method_exists($this, 'displayName')
            ? $this->displayName()
            : class_basename(static::class).' #'.$this->getKey();
    }

    // ------------------------------------------------------------------ lifecycle hooks

    /**
     * The definition this record enters when submitted, or null to use the module default.
     *
     * Documents override this to prefer the category's configured route, which is how one tenant
     * runs a four-stage drawing approval beside a one-stage memo approval without code.
     */
    public function resolveWorkflowDefinition(): ?Workflow
    {
        return null;
    }

    /**
     * Whether this record is routed for approval at all.
     *
     * DISTINCT FROM `resolveWorkflowDefinition()` RETURNING NULL, and the distinction is the whole
     * reason this hook exists. Null there means "I have no preference — use the module default",
     * which is right for a memo whose type names no route. False here means "do not route this
     * record, and do not fall back", which is what an uncontrolled register is: a company that
     * files correspondence received from a client is recording what arrived, not asking anybody to
     * approve it, and inventing a review step for it would put decisions in inboxes that nobody
     * asked for and leave the document unreadable until somebody clears them.
     *
     * True by default, because a record that adopts this trait has been made routable on purpose.
     */
    public function requiresWorkflow(): bool
    {
        return true;
    }

    /**
     * Called by WorkflowEngine inside the closing transaction.
     *
     * Empty by default so a model may adopt the trait and route without caring what happens at the
     * end — plenty of records only need the audit trail. Documents override all three to move
     * status, stamp approvals onto the version and supersede the previous revision.
     */
    public function onWorkflowApproved(WorkflowInstance $instance): void {}

    public function onWorkflowRejected(WorkflowInstance $instance): void {}

    public function onWorkflowRevisionRequested(WorkflowInstance $instance): void {}

    public function onWorkflowCancelled(WorkflowInstance $instance): void {}
}
