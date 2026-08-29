# 06 — RBAC & Permission Matrix

## 1. Permission key grammar

```
<module>.<action>[.<qualifier>]
```

Examples: `document.approve`, `document.view_confidential`, `project.manage`,
`hse_incident.investigate`, `payroll.run`, `purchase_request.approve`.

**Modules:** `organization, org_unit, user, role, employee, project, project_member, task,
document, transmittal, workflow, form, meeting, memo, announcement, daily_report, deliverable,
drawing, rfi, submittal, change_request, hse_incident, hse_risk, hse_inspection, hse_observation,
toolbox_talk, permit, corrective_action, hse_training, quality_inspection, ncr, vendor, client,
contract, purchase_request, rfq, quotation, purchase_order, goods_receipt, material_request,
warehouse, inventory_item, stock, asset, asset_maintenance, vehicle, fuel, visitor, attendance,
timesheet, leave, recruitment, performance, payroll, expense, income, budget, payment, report,
kpi, audit_log, setting, custom_field, template, numbering, escalation`

**Actions:** `view, view_any, view_confidential, create, edit, delete, submit, review, approve,
reject, sign, download, print, export, assign, reassign, archive, restore, manage`

Permissions are a **global catalogue** (not org-scoped) — the *grants* are org-scoped. This keeps
the catalogue consistent while roles stay fully configurable per company (§5).

## 2. Grant record

```
permission_role: role_id · permission_id · scope
scope ∈ { own, assigned, team, section, department, division, project, organization }
```

A role is therefore a **named bundle of (permission, scope) pairs** — which is why administrators
can invent any role the company needs without code changes.

## 3. Seeded role templates

These ship as editable starting points (§5 — "examples only"), each marked `is_system=false` so an
administrator may rename, edit or delete them. Only `Super Administrator` is protected.

| Role | Representative grants (permission : scope) |
|------|-------------------------------------------|
| **Super Administrator** | platform flag — bootstrap, tenant provisioning, audited |
| **System Administrator** | `setting.manage:organization`, `role.manage:organization`, `user.manage:organization`, `workflow.manage:organization`, `custom_field.manage:organization`, `audit_log.view:organization` |
| **Managing Director / CEO** | `*.view:organization`, `*.approve:organization`, `report.export:organization`, `kpi.view:organization` — **no** `setting.manage`, **no** `payroll.run` unless granted |
| **Executive Director / GM** | `project.view:organization`, `report.view:organization`, `purchase_request.approve:organization`, `expense.approve:organization` |
| **Operations Manager** | `project.view:organization`, `task.manage:organization`, `hse_incident.view:organization`, `corrective_action.view:organization`, `purchase_request.approve:organization` |
| **Project Director** | `project.manage:project`, `project_member.manage:project`, `document.approve:project`, `change_request.approve:project` |
| **Project Manager** | `project.manage:project`, `project_member.assign:project`, `task.manage:project`, `document.review:project`, `meeting.manage:project`, `purchase_request.submit:project` |
| **Department Head** | `*.view:department`, `task.assign:department`, `project_member.assign:department`, `leave.approve:department`, `document.approve:department` |
| **Engineering Manager** | `deliverable.approve:department`, `drawing.approve:department`, `rfi.respond:department`, `document.approve:department` |
| **Lead Engineer** | `deliverable.review:department`, `document.review:department`, `task.assign:team`, `rfi.respond:assigned` |
| **Engineer** | `deliverable.create:own`, `document.view:department`, `document.create:own`, `task.view:assigned`, `daily_report.create:own`, `project.view:project` |
| **HSE Manager** | `hse_*.manage:organization`, `permit.approve:organization`, `corrective_action.manage:organization`, `hse_training.manage:organization` |
| **HSE Officer** | `hse_incident.create:project`, `hse_inspection.create:project`, `toolbox_talk.manage:project`, `hse_observation.create:project`, `permit.review:project` |
| **QA/QC Manager** | `quality_inspection.manage:organization`, `ncr.approve:organization`, `corrective_action.verify:organization` |
| **QA/QC Officer** | `quality_inspection.create:project`, `ncr.create:project`, `ncr.view:department` |
| **Document Controller** | `document.manage:organization`, `document.archive:organization`, `transmittal.manage:organization`, `numbering.manage:organization` |
| **HR Manager** | `employee.manage:organization`, `leave.approve:organization`, `attendance.manage:organization`, `recruitment.manage:organization`, `payroll.run:organization` |
| **HR Officer** | `employee.view:organization`, `attendance.edit:organization`, `leave.view:organization`, `recruitment.edit:organization` |
| **Finance Manager** | `expense.approve:organization`, `budget.manage:organization`, `payment.manage:organization`, `income.manage:organization`, `report.export:organization` |
| **Finance Officer** | `expense.create:department`, `expense.view:department`, `payment.create:department`, `income.create:department` |
| **Procurement Manager** | `purchase_request.manage:organization`, `rfq.manage:organization`, `purchase_order.approve:organization`, `vendor.manage:organization` |
| **Procurement Officer** | `purchase_request.view:department`, `rfq.create:department`, `quotation.manage:department`, `purchase_order.create:department` |
| **Store Manager** | `warehouse.manage:organization`, `stock.manage:organization`, `goods_receipt.approve:organization`, `material_request.approve:organization` |
| **Store Officer** | `stock.create:team`, `goods_receipt.create:team`, `inventory_item.view:organization` |
| **Fleet Manager** | `vehicle.manage:organization`, `fuel.manage:organization`, `asset_maintenance.manage:organization` |
| **Driver** | `vehicle.view:assigned`, `fuel.create:own`, `task.view:assigned` |
| **Office Administrator** | `visitor.manage:organization`, `meeting.manage:organization`, `memo.create:organization`, `employee.view:organization` — **no** payroll, **no** finance |
| **Site Administrator** | `visitor.manage:project`, `attendance.edit:project`, `daily_report.view:project`, `memo.create:project` |
| **Project Administrator** | role assignment scoped `scope_type=project` — `project.edit`, `task.manage`, `meeting.manage`, `document.view` within that one project only |
| **Department Administrator** | role assignment scoped `scope_type=org_unit` — `employee.view`, `task.manage`, `leave.view` within that one unit only |
| **Security Officer** | `visitor.manage:organization`, `attendance.view:organization` |
| **General Employee** | `task.view:assigned`, `document.view:assigned`, `leave.create:own`, `attendance.create:own`, `daily_report.create:own`, `meeting.view:assigned` |
| **Client** | `project.view:assigned`, `document.view:assigned` (external portal, ACL-driven) |
| **Contractor / Consultant** | `project.view:project`, `task.view:assigned`, `document.view:assigned`, `daily_report.create:own` |

Note the deliberate absence of unlimited access on every administrator variant (§10).

## 4. Action → gate mapping

| Action | Extra requirement beyond view |
|--------|-------------------------------|
| `create` | permission only (no record yet); org + unit/project derived from actor |
| `edit` | record not in a terminal/locked status; `StatusMachine::isEditable()` |
| `delete` | soft delete; blocked when a workflow instance is active |
| `submit` | actor is owner or has `submit`; record in `draft`; starts workflow |
| `review` / `approve` / `reject` | actor is the **resolved assignee of the current stage** — the permission alone is not sufficient |
| `sign` | `approve` + `signature_path` present on employee record |
| `download` | `view` + `download`; every download is audit-logged (§59) |
| `export` | `export` on the module + the same visibility filter as the index |
| `assign` / `reassign` | target user must be within the actor's scope |
| `archive` / `restore` | `document.archive`; only from `approved`/`published` |
| `manage` | implies view/create/edit/delete/assign within the granted scope |

**Critical rule:** holding `document.approve` does *not* let a user approve an arbitrary document.
They must also be the resolved assignee of the active workflow stage. Permission answers *"may this
kind of person approve?"*; workflow answers *"is it your turn?"*.

## 5. Server-side enforcement (§6, §84)

- Every controller action calls `$this->authorize(...)` or uses an authorizing FormRequest.
- Every index/export/search uses `visibleTo()`.
- Blade `@can` only hides UI; it is never the enforcement point.
- A feature test asserts that **every** route in `routes/web.php` is covered by auth + a policy or
  explicit permission middleware. Uncovered routes fail the build.

## 6. Permission caching

Effective permissions cache under `perm:{user_id}:{org_id}` with the org's
`permissions_version` counter in the key. Any change to roles, grants, assignments or org structure
bumps the counter, invalidating every affected user instantly without cache scanning.
