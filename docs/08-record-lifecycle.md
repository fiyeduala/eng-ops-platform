# 08 — Record Lifecycle Architecture (Engine 3)

## 1. What is a record

Every major business object — project, task, document, HSE incident, purchase request, expense,
leave request, meeting, NCR, inspection, form submission — is a **record** and gets the same
capabilities (§23) by composing traits:

| Trait | Provides |
|-------|----------|
| `BelongsToOrganization` | tenant scope + auto-fill `organization_id` |
| `HasNumber` | configurable auto-numbering (§64) |
| `HasStatus` | status + guarded transitions (§24, §63) |
| `HasWorkflow` | approval routing (§78) |
| `HasAttachments` | polymorphic files on private storage |
| `HasComments` | contextual discussion (§71) |
| `HasFollowers` | subscription to notifications |
| `HasActivity` | human-readable timeline (§60) |
| `Auditable` | field-level audit trail (§59) |
| `HasCustomFields` | admin-defined fields, no schema change (§62) |
| `Scopeable` | `visibleTo($user, $permission)` query scope |
| `HasRecordPermissions` | per-record ACL (Gate 4) |

## 2. Default lifecycle (§24)

```
draft ──submit──▶ submitted ──▶ under_review ──┬─approve──▶ approved ──sign──▶ signed
  ▲                                            │                                  │
  │                                            └─request_revision──▶ revision_    │
  │                                                                  requested    │
  └────────────────── resubmit ◀── resubmitted ◀──────────────────────┘           │
                                                                                   ▼
                                          rejected                published ──▶ archived
```

Per-entity lifecycles are configurable; these are the seeded defaults.

## 3. Status system (§63)

```
statuses            entity_type, key, label, color, sort, is_initial, is_terminal,
                    is_editable, category(open|in_progress|done|cancelled)
status_transitions  entity_type, from_status_id, to_status_id, permission_key,
                    guard (class name), requires_comment, requires_workflow_complete
```

`StatusMachine` is the **only** way a status changes:

```php
StatusMachine::transition($record, 'approved', $actor, comment: '…');
```

It verifies, in order: the transition exists → actor holds `permission_key` → the guard class
passes → workflow prerequisites are satisfied → then writes the status, activity entry and audit
entry inside one transaction, and dispatches `RecordStatusChanged`.

**A submitted `status_id` from a form is never trusted.** Requests carry an *action* (`submit`,
`approve`), not a target status. This closes the §63 forgery hole.

An administrator may add statuses (e.g. `on_hold`, `awaiting_client`) and wire transitions, but
cannot create a transition that skips a required workflow — the builder refuses it.

## 4. Numbering (§64)

```
numbering_schemes  entity_type, pattern, reset_period, padding, is_active
number_sequences   scheme_id, period_key, next_value      ← UNIQUE(scheme_id, period_key)
```

Pattern tokens: `{PREFIX}` `{ORG}` `{PROJECT}` `{DEPT}` `{DISCIPLINE}` `{TYPE}` `{YYYY}` `{YY}`
`{MM}` `{SEQ:n}`

| Entity | Default pattern | Example |
|--------|-----------------|---------|
| Project | `PROJECT-{YYYY}-{SEQ:3}` | `PROJECT-2026-001` |
| Document | `DOC-{DISCIPLINE}-{YYYY}-{SEQ:3}` | `DOC-ENG-2026-002` |
| HSE incident | `HSE-INC-{YYYY}-{SEQ:3}` | `HSE-INC-2026-003` |
| Purchase order | `PO-{YYYY}-{SEQ:3}` | `PO-2026-004` |
| Purchase request | `PR-{YYYY}-{SEQ:3}` | `PR-2026-009` |
| NCR | `NCR-{YYYY}-{SEQ:3}` | `NCR-2026-001` |

Allocation runs in a transaction with `SELECT … FOR UPDATE` on the sequence row, so concurrent
creates never collide. Numbers are allocated **on first save**, never on form render, so abandoned
drafts do not burn sequence values.

## 5. Custom fields (§62)

```
custom_fields        entity_type, key, label, type, options json, validation, sort,
                     is_required, is_filterable, applies_when json
custom_field_values  entity_type, entity_id, custom_field_id,
                     value_text, value_number, value_date, value_json
```

Typed columns (rather than one stringly-typed column) keep filtering and sorting index-friendly.
`applies_when` allows a field to appear only for, say, projects of type "EPC". No migration is ever
needed to add a field (§62).

## 6. Attachments & comments

`attachments` store `disk`, `path`, `original_name`, `mime`, `size`, `sha256`. Files always land on
the **private** disk; serving goes through an authorizing controller (§67).

`comments` are polymorphic, threaded one level, support `@mentions` (which notify only users who
can see the parent record, §68) and an `is_internal` flag so client-visible records can carry
internal-only discussion. This is the §71 "contextual communication" model — discussion lives on the
record, not in a parallel chat product.

## 7. Activity timeline (§60)

`ActivityRecorder::record($subject, $verb, $description, $properties, $causer)` produces the record
timeline:

```
PROJECT ALPHA
  created → departments assigned → personnel assigned → task created →
  engineering document submitted → review requested → revision requested →
  revision uploaded → approved → signed → archived
```

Timeline entries are themselves filtered: a viewer sees only entries whose subject they may view.

## 8. Soft deletes & archival

- `delete` is a soft delete; blocked while a workflow instance is active.
- `archive` is a *status*, not a deletion — archived records stay queryable and auditable.
- `restore` requires `restore` permission and writes an audit entry.
- Audit logs are never deleted with their subject.
