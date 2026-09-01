# 07 — Workflow Architecture (Engine 2)

## 1. Why one engine (§78)

Documents, purchase requests, expenses, leave, HSE incidents, permits, NCRs, corrective actions,
project deliverables and every custom form need the *same* thing: a configurable sequence of
responsible parties who review, approve or sign. Implementing that per module would produce ten
divergent, separately-buggy approval systems. There is exactly **one**.

Any model becomes workflow-capable by adding the `HasWorkflow` trait. No other change.

## 2. Definition vs instance

```
DEFINITION (configuration, edited by admins)      RUNTIME (per record)
─────────────────────────────────────────         ──────────────────────────────
workflows                                          workflow_instances
  organization_id, name, entity_type,                workflow_id, subject_type/id,
  trigger, version, is_active                        status, current_stage_instance_id,
      │                                              started_at, completed_at
      └── workflow_stages                                │
            sequence, name, assignee_type,               └── workflow_stage_instances
            assignee_ref, action_type, sla_hours,              stage_id, resolved_assignees,
            quorum, on_reject, allow_delegate,                 status, due_at, acted_by,
            notify json, auto_actions json                     acted_at, decision, comment
                  │                                                  │
                  └── workflow_stage_conditions                      └── approvals ──1:1── signatures
                        field, operator, value, logic
```

Editing a workflow creates a **new version**. Running instances keep the version they started on, so
changing the process never corrupts in-flight approvals.

### Which definition a record gets

Three questions, asked in this order, and the order is the whole design:

1. **`$record->requiresWorkflow()`** — does this record get routed at all? Returning false ends it:
   no route, no instance, and *no fallback*. An uncontrolled register (correspondence received,
   vendor data) is recording what arrived, not asking anybody to approve it.
2. **`$record->resolveWorkflowDefinition()`** — does this record name its own route? A document
   category, a memo type or a requisition class may point at a specific workflow.
3. **The module default** — the tenant's active workflow for this `entity_type` and trigger.

Steps 1 and 2 look alike and are not. Null at step 2 means *"I have no preference, use the module
default"*, which is right for a memo whose type names no route. False at step 1 means *"do not route
this, and do not fall back"*, which is a decision somebody made on a configuration form. Collapsing
the two — as the engine did until `requiresWorkflow()` existed — makes the fallback overrule the
configuration: a register whose "requires approval" box was unticked would still send every incoming
client letter out for technical review, with the screen saying one thing and the system doing
another. Pinned by `WorkflowEngineTest::a_register_that_requires_no_approval_issues_without_a_route`.

## 3. Dynamic assignee resolution (§19)

This is the single most important design decision in the engine. Stages never name a person.

| `assignee_type` | `assignee_ref` | Resolves to |
|-----------------|----------------|-------------|
| `user` | user id | that user (discouraged; flagged in the builder) |
| `role` | role id | all active holders of the role, narrowed by the record's org unit/project |
| `org_unit` | org_unit id | members of that unit |
| `org_unit_head` | *(null)* | head of the **record's** org unit |
| `parent_unit_head` | *(null)* | head of the parent of the record's org unit |
| `project_manager` | *(null)* | `projects.manager_id` of the record's project |
| `project_role` | project_role id | members of the record's project holding that project role |
| `department_head_of_project` | org_unit id | head of that department **on that project** |
| `supervisor` | *(null)* | `employees.supervisor_id` of the record's owner |
| `manager_chain` | depth n | nth ancestor in the reporting chain |
| `record_owner` | *(null)* | `created_by` / `owner_id` |
| `record_field` | column name | user id held in a column on the record |
| `custom` | resolver class | a registered `AssigneeResolverContract` implementation |

> "Send to the Engineering Manager **of the project**" — not "send to John". If John leaves, the
> workflow continues automatically with whoever now holds that position (§19).

Resolution happens **when the stage is entered**, never at definition time, and the resolved set is
stored on the stage instance for audit. If resolution yields nobody, the instance moves to
`stalled`, notifies the workflow owner and the system administrator, and never silently skips.

## 4. Conditional routing (§20)

Conditions attach to stages; a stage whose conditions evaluate false is skipped.

```json
{
  "logic": "and",
  "rules": [
    { "field": "total_amount", "operator": ">=", "value": 500000 },
    { "field": "project.category", "operator": "in", "value": ["capex"] }
  ]
}
```

Worked example (§20) — one workflow, two paths:

| Stage | Condition | ≤ ₦500,000 | > ₦500,000 |
|-------|-----------|-----------|-----------|
| 1 Department Head | — | ✓ | ✓ |
| 2 Procurement | — | ✓ | ✓ |
| 3 Finance | — | ✓ | ✓ |
| 4 Operations Manager | `amount > threshold` | — | ✓ |
| 5 Managing Director | `amount > threshold` | — | ✓ |

The threshold is a **setting** (`settings.procurement.high_value_threshold`), referenced as
`{{setting:procurement.high_value_threshold}}` so finance can change it without touching the
workflow.

`ConditionEvaluator` supports `= ≠ > >= < <= in not_in contains between is_null changed`, dotted
paths into relations, `{{setting:…}}` and `{{custom_field:…}}` tokens. Field resolution is
whitelisted per entity type — arbitrary expressions are never evaluated.

## 5. Stage actions and outcomes

`action_type`: `review · approve · sign · acknowledge · input · notify_only`

`quorum`: `any` (first responder decides) · `all` (every assignee must act) · `n_of_m`

| Decision | Effect (configurable via `on_reject`) |
|----------|---------------------------------------|
| `approve` | advance to next passing stage; complete instance if none |
| `reject` | `terminate` (record → `rejected`) · `return_to_previous` · `return_to_owner` |
| `request_revision` | record → `revision_requested`; owner may resubmit; instance re-enters from stage 1 or from the returning stage, per config |
| `delegate` | reassign to another authorized user; original assignee recorded |
| `abstain` | counts toward quorum without deciding |

Resubmission (§24) creates a **new attempt** on the same instance — the prior attempt's decisions,
comments and timings are preserved for audit.

## 6. Automatic side effects (§32)

A stage carries `auto_actions`: a JSON list of keyed instructions run **when the stage opens**,
each independently, by `AutoActionRunner`.

```json
[
  { "verb": "create_record", "record": "investigation",
    "title": "Investigation into the reported incident",
    "due_in_days": 14, "assignee_type": "role", "assignee_ref": "8" },
  { "verb": "create_record", "record": "corrective_action",
    "title": "Make the scaffold platform safe",
    "due_in_days": 7, "assignee_type": "user", "assignee_ref": "31" },
  { "verb": "set_status",  "status": "under_investigation" },
  { "verb": "create_task", "title": "Close out the review comments", "due_in_days": 5 },
  { "verb": "notify",      "message": "This report needs a decision today." }
]
```

Four verbs exist and no more (`AutoActionRunner::VERBS`). An action is a keyed instruction with
named parameters — never a callable, a class name or an expression — so there is no path from a
text field in the builder to code execution. Anything not in the list is refused at the form.

**Three rules the runner is built around:**

1. **Only the verbs in `VERBS` exist.** A `match` over a fixed list, not something clever.
2. **Notifications go through the dispatcher**, which re-asks the subject's own policy for every
   candidate. An auto-action names *who*; the dispatcher decides *whether they may hear it*, and
   the dispatcher wins (§68).
3. **A failing auto-action must not take the approval with it.** Each verb runs in its own
   try/catch. The stage opening is what people are waiting on; a misconfigured side effect costs
   somebody a puzzled look in the log, not every document in the company. The failure is written to
   the record's own timeline as well as the log, where the person asking "why is this still Draft"
   is actually looking.

### `create_record` and `SpawnsFromRecord`

`create_record` is how "an incident goes for review → an investigation opens → a corrective action
is raised and assigned with a deadline" becomes a sentence on a form.

The runner names no module. It resolves the configured morph key through the morph map and asks the
**class** whether it can be opened from another record — `App\Engines\Workflow\Contracts\SpawnsFromRecord`.
The builder's picker is discovered the same way (`WorkflowCatalogue::spawnableRecords()`), so adding
a spawnable type in a later phase is one interface on one model, with no list anywhere to forget.

The implementation owns the *should I?* question, not just the *how*:

- **Idempotence.** A re-entered stage runs its auto-actions again — a report sent back and
  resubmitted, an escalation that reopens a stage. `Investigation::spawnFrom()` returns `null` when
  the incident already has one, because "one per incident" is a fact about investigations and the
  engine cannot know it.
- **Applicability.** A near miss with no injury may warrant no investigation under a company's own
  rules. Returning `null` is a legitimate, silent outcome.

Throwing is reserved for genuine misconfiguration, and rule 3 above catches it.

The created record inherits the subject's project, unit and classification, so an investigation into
a confidential incident is never opened more openly than the incident it is about (§68).

**`auto_actions.*.record` is validated against the discovered list.** `validated()` returns only
what the rules name: an unvalidated key is stripped between the form and the stage, and the action
then saves cleanly, reports success, and throws on the first record that reaches the stage.

**Entering a route is `RegisterController::submitForApproval()`**, shared by every register whose
model uses `HasWorkflow` — not a per-module `submit()`. Gated on `update`: putting your own record
forward for review is an act of authorship, and a module policy that freezes a record (a closed
incident) already refuses `update`, so that refusal carries here for free.

Proven end-to-end by `tests/Feature/Hse/IncidentSpawnsInvestigationTest`, which configures the whole
route by POSTing to `workflows.store`, `workflows.stages.store` and `workflows.publish` and touches
no service.

## 7. SLA and escalation (§57)

Each stage carries `sla_hours`. `due_at = entered_at + sla_hours` (business-hours aware, using the
organization's working calendar).

`escalation_rules` are separate and reusable across entity types:

```
after_days 3  → notify supervisor
after_days 7  → notify org_unit_head
after_days 14 → notify role: Operations Manager
```

`EscalationService` runs from the scheduler, is idempotent (records `last_escalated_level`), and
never notifies a recipient who cannot see the underlying record (§68).

## 8. The approvals inbox (§21, §22)

`workflow_stage_instances` where `status = pending` and the acting user ∈ `resolved_assignees`
**is** the My Approvals inbox — one query across every module.

```
MY PENDING ACTIONS
  Document ENG-2026-014 · Technical Review · Project Alpha
  Submitted by John · Deadline 2 September · 2 days remaining
  [Open] [Approve] [Reject] [Request Revision]
```

Indexed by `(assignee_id, status, due_at)`. Users never search for pending work.

## 9. Integrity guarantees

| Guarantee | Mechanism |
|-----------|-----------|
| Only the current assignee may act | `WorkflowPolicy::act()` checks resolved assignees on the **current** stage instance |
| No status forgery (§63) | Status changes flow through `StatusMachine`; workflow is the only caller for workflow-driven states |
| No double action | Unique constraint `(stage_instance_id, user_id)` on approvals; optimistic lock on instance version |
| No lost work on reorg | Assignees resolve at runtime from live org data |
| Full traceability | Every transition writes `ActivityLog` + `AuditLog` |
| Deterministic replay | Instance stores the workflow **version** it started on |
| No unsigned approval | `RecordDocumentSignature` runs **before** the notification listener, inside the engine's transaction: if the evidence cannot be written the decision rolls back with it |
| No route where none was asked for | `requiresWorkflow()` is a veto the module-default fallback cannot overrule (§2) |

## 10. Public API

```php
$engine->start($record, trigger: 'submitted', actor: $user): WorkflowInstance;
$engine->act($stageInstance, $user, Decision::Approve, comment: '…');
$engine->pendingFor($user): Collection;              // My Approvals
$engine->cancel($instance, $user, reason: '…');
$engine->reassign($stageInstance, $toUser, $actor);
$record->workflowInstance;  $record->currentStage;  $record->approvalHistory;
```

What a routable model provides (`HasWorkflow`):

```php
$record->requiresWorkflow(): bool;                   // veto — default true
$record->resolveWorkflowDefinition(): ?Workflow;     // preference — default null
$record->workflowEntityType(): string;               // which definitions apply
```
