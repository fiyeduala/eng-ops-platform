# 03 — Database ERD

## Conventions

- Every tenant table carries `organization_id` (FK, indexed first in every composite index).
- Every record table carries `created_by`, `updated_by`, timestamps, and `deleted_at` where
  soft-deletes are meaningful.
- Money is `decimal(18,2)` plus a `currency` char(3). Never float.
- Polymorphic columns are named `<name>_type` / `<name>_id` and always indexed as a pair.
- Enum-like values live in `statuses` / lookup tables when configurable (§63), and in PHP enums
  when they are structural invariants (e.g. access scope, confidentiality).

---

## A. Foundation & Access

```
organizations ──1:N── org_units ──self──┐
     │                    │  type: division|department|section|team
     │                    │  parent_id, path, depth, head_employee_id
     │
     ├──1:N── locations              (sites, offices, yards)
     ├──1:N── positions              (job titles, grade, org_unit_id nullable)
     ├──1:N── users ──1:1── employees
     ├──1:N── roles ──N:M── permissions   (pivot: permission_role.scope)
     └──1:N── settings                (key, value json, group)

users            organization_id, name, email, password, is_super_admin, is_active,
                 last_login_at, must_change_password, two_factor_*
employees        organization_id, user_id, employee_no, org_unit_id, position_id,
                 supervisor_id → employees.id, location_id, employment_type,
                 employment_status, hired_on, exited_on, signature_path, meta json
role_user        role_id, user_id, scope_type, scope_id, starts_at, ends_at
record_permissions  record_type, record_id, grantee_type, grantee_id, ability,
                    effect(allow|deny), granted_by, expires_at
employee_skills / employee_qualifications / employee_certifications
                 (certifications feed the Expiry engine: expires_on, document_id)
```

---

## B. Projects

```
projects ──┬──1:N── project_departments   (org_unit_id, lead_employee_id, status)
           ├──1:N── project_members       (user_id, project_role_id, dates, responsibilities)
           ├──1:N── project_phases ──1:N── project_milestones
           ├──1:N── project_risks
           ├──1:N── project_issues
           ├──1:N── project_rfis
           ├──1:N── project_submittals
           ├──1:N── change_requests
           └──1:N── project_reports

projects        organization_id, number, name, code, client_id, project_type_id,
                manager_id, sponsor_id, location_id, status_id, confidentiality,
                planned_start, planned_end, actual_start, actual_end,
                contract_value, currency, progress_percent, health
project_roles   organization_id, name, slug            ← configurable project-role catalogue
project_members organization_id, project_id, user_id, project_role_id, org_unit_id,
                responsibilities, starts_on, ends_on, is_active
```

`project_members` is the single source of truth for §14 project visibility.

---

## C. Record, Document & Workflow

```
documents ──1:N── document_versions
    │                  revision, file_path, file_hash, size, mime, author_id,
    │                  change_summary, is_current, superseded_at
    ├──N:1── document_categories (self-nesting)
    ├──1:N── record_permissions (polymorphic)
    └──N:M── transmittals via transmittal_items

workflows ──1:N── workflow_stages ──1:N── workflow_stage_conditions
    │                  sequence, name, assignee_type, assignee_ref, action_type,
    │                  sla_hours, quorum, on_reject, allow_delegate
    └──1:N── workflow_instances (polymorphic subject)
                  └──1:N── workflow_stage_instances
                                └──1:N── approvals ──1:1── signatures

attachments      attachable_type/id, disk, path, original_name, mime, size, hash, uploaded_by
comments         commentable_type/id, parent_id, body, is_internal, author_id
followers        followable_type/id, user_id
tasks            linked_type/id (nullable), project_id, org_unit_id, assignee_id,
                 priority, status_id, start_on, due_on, completed_at, parent_id
task_checklist_items / task_dependencies / task_comments (via comments)
```

---

## D. Configuration engine

```
statuses           entity_type, key, label, color, sort, is_initial, is_terminal
status_transitions entity_type, from_status_id, to_status_id, permission_key, guard
numbering_schemes  entity_type, pattern, reset_period(none|yearly|monthly), padding
number_sequences   scheme_id, period_key, next_value          ← atomic increment
custom_fields      entity_type, key, label, type, options json, rules, sort, is_required
custom_field_values entity_type, entity_id, custom_field_id, value_text/number/date/json
forms ──1:N── form_fields
forms              name, module, workflow_id, entity_type, is_active, version
form_submissions   form_id, submitter_id, project_id, org_unit_id, data json, status_id
form_submission_values form_submission_id, form_field_id, value_*   ← queryable mirror of data
templates          type, name, body(html), placeholders json
escalation_rules   entity_type, condition json, after_days, notify_type, notify_ref, sequence
expiry_watches     watchable_type/id, field, thresholds json, last_notified_at
kpi_definitions    name, module, formula json, target, unit, direction, period
kpi_snapshots      kpi_definition_id, period_key, scope_type, scope_id, value
```

---

## E. Engineering / HSE / QA-QC

```
engineering_deliverables  project_id, discipline_id, document_id, wbs, planned/actual dates
disciplines               name, code                              ← configurable (§38)
drawings (documents with category = drawing) + drawing_register view
rfis / technical_queries  project_id, raised_by, assigned_to, due_on, response, status_id
transmittals ──1:N── transmittal_items (document_version_id)

hse_incidents        project_id, org_unit_id, category_id, severity, occurred_at, location_id,
                     reported_by, description, immediate_action, status_id, investigation json
hse_risks            hazard, likelihood, severity, risk_score (generated), controls,
                     residual_likelihood, residual_severity, residual_score, owner_id, review_on
hse_inspections      checklist_form_id, form_submission_id, project_id, inspector_id, score
hse_observations     type(unsafe_act|unsafe_condition|positive), ...
toolbox_talks ──N:M── toolbox_talk_attendees (signature_path)
permits              permit_type_id, project_id, valid_from, valid_to, workflow_instance
corrective_actions   source_type/id (incident|ncr|inspection|audit), owner_id, due_on,
                     evidence, verified_by, verified_at, status_id
hse_trainings ──N:M── employees (certificate_document_id, completed_on, expires_on)

quality_inspections  inspection_request_no, checklist_form_id, project_id, result
ncrs                 project_id, raised_by, description, root_cause, disposition,
                     status_id (Issue→Investigation→CorrectiveAction→Verification→Closure)
```

---

## F. Commercial operations

```
vendors / clients        code, name, category, contacts json, status, rating
vendor_documents         (certifications, insurance) expires_on → Expiry engine
contracts                party_type/id, project_id, value, start_on, end_on, document_id

purchase_requests ──1:N── purchase_request_items
rfqs ──N:M── vendors ──1:N── quotations ──1:N── quotation_items
purchase_orders ──1:N── purchase_order_items
goods_receipts ──1:N── goods_receipt_items  → stock_transactions

warehouses ──1:N── warehouse_locations
stock_items              sku, name, category, uom, reorder_level, unit_cost
stock_balances           stock_item_id, warehouse_id, quantity   ← derived, reconciled
stock_transactions       type(receipt|issue|transfer|return|adjustment), item, warehouse,
                         quantity(+/-), reference_type/id, project_id, performed_by
material_requests        project_id, requested_by, items, status_id

assets ──1:N── asset_maintenances / asset_assignments
vehicles ──1:N── vehicle_maintenances / fuel_records / trips / vehicle_documents

cost_centers             code, name, org_unit_id, project_id, manager_id, is_active
budgets ──1:N── budget_lines (category, allocated_amount); approved lines are frozen
project_costs            source_type/id, project_id, org_unit_id, vendor_id,
                         currency, exchange_rate, amount, base_currency, base_amount
expenses / incomes / budgets / budget_lines / cost_centers / payments
```

---

## G. People

```
attendances     employee_id, work_date, clock_in_at, clock_out_at, location_id,
                latitude, longitude, source(web|kiosk|admin), adjusted_by, notes
timesheets ──1:N── timesheet_entries (project_id, task_id, hours, date)
leave_types     name, days_per_year, is_paid, requires_approval, accrual json
leave_requests  employee_id, leave_type_id, from_date, to_date, days, reason, status_id
payroll_runs ──1:N── payroll_items ──1:N── payroll_item_lines
payroll_components  name, type(earning|deduction), calculation(fixed|percent|formula),
                    formula, is_taxable, sort                       ← no hard-coded tax (§47)
recruitments / applicants / onboardings / trainings / performance_reviews /
promotions / transfers / disciplinary_records / exits
visitors        name, organization, host_employee_id, purpose, arrived_at, departed_at, badge_no
```

---

## H. Cross-cutting

```
meetings ──1:N── meeting_participants
         ──1:1── meeting_minutes ──1:N── meeting_decisions
                                 ──1:N── meeting_actions → tasks
announcements / memos ──N:M── audiences (org_unit | project | role | user)
notifications        (Laravel default) + notification_preferences + push_subscriptions
audit_logs           organization_id, user_id, action, auditable_type/id, old_values json,
                     new_values json, ip, user_agent, url, created_at       ← APPEND ONLY
activity_logs        organization_id, causer_id, subject_type/id, project_id, verb,
                     description, properties json, created_at
reports / report_schedules / saved_filters
```

---

## Indexing strategy (§95)

| Pattern | Index |
|---------|-------|
| Every tenant table | `(organization_id, id)` and `(organization_id, created_at)` |
| Scope filtering | `(organization_id, org_unit_id)`, `(organization_id, project_id)` |
| Assignment lookups | `(assignee_id, status_id)`, `(organization_id, assignee_id, due_on)` |
| Polymorphic | `(subject_type, subject_id)`, `(auditable_type, auditable_id)` |
| Approval inbox | `workflow_stage_instances (assignee_id, status, due_at)` |
| Documents | `(organization_id, document_category_id)`, `documents.number` unique per org |
| Search | `FULLTEXT` on `name/title/description` of searchable tables |
| Expiry sweeps | `(organization_id, expires_on)` on every expiring entity |
| Stock | `(stock_item_id, warehouse_id)` unique on `stock_balances` |

## Integrity rules

- `stock_balances` is a materialized projection of `stock_transactions`; a nightly job reconciles
  and reports drift.
- `document_versions.is_current` is maintained inside a transaction; only one current per document.
- `number_sequences` increments use `SELECT … FOR UPDATE` inside a transaction (§64).
- `audit_logs` has no update or delete route; the DB user for the app may INSERT/SELECT only (§59).
- `org_units.path` is rebuilt transactionally on reparent; a cycle check runs before write.
