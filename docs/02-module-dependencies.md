# 02 — Module Dependency Map

## 1. Dependency direction

Modules depend **downward** onto engines, and **sideways** only through events or explicit service
interfaces. No engine may depend on a domain module.

```
                         ┌──────────────────────────────┐
                         │   EXECUTIVE / REPORTING      │
                         │  dashboards · KPI · exports  │
                         └──────────────┬───────────────┘
                                        │ reads
   ┌────────────────────────────────────┴────────────────────────────────────┐
   │                          DOMAIN MODULES                                  │
   │                                                                          │
   │  Projects ── Engineering ── HSE ── QA/QC ── Meetings ── Daily Ops        │
   │      │                                                                   │
   │  Procurement ── Inventory ── Assets ── Fleet ── Vendors ── Clients       │
   │      │                                                                   │
   │  HR ── Attendance ── Leave ── Payroll ── Visitors ── Finance             │
   └────────────────────────────────────┬────────────────────────────────────┘
                                        │ consume
   ┌────────────────────────────────────┴────────────────────────────────────┐
   │                            ENGINES                                       │
   │  Access · Workflow · Record/Document · Audit · Configuration            │
   └────────────────────────────────────┬────────────────────────────────────┘
                                        │
   ┌────────────────────────────────────┴────────────────────────────────────┐
   │              FOUNDATION: Organization · Users · Employees               │
   └──────────────────────────────────────────────────────────────────────────┘
```

## 2. Cross-module integration points (§90)

The system is integrated because these links exist as **real foreign keys and polymorphic
relations**, not as duplicated data.

| Source | Produces | Target | Mechanism |
|--------|----------|--------|-----------|
| Meeting | Action items | Task | `MeetingActionConverted` listener → `TaskService::createFrom()` |
| HSE Incident | Investigation + corrective action | CorrectiveAction, Task | `IncidentCreated` event → workflow |
| NCR | Corrective action | CorrectiveAction, Task | Workflow stage `on_enter` action |
| Purchase Request | Approval chain | WorkflowInstance | `HasWorkflow` |
| Purchase Order | Goods receipt | StockTransaction | Receipt-acceptance transaction |
| Goods Receipt | Cost posting | ProjectCost | Receipt-acceptance transaction |
| Document approval | Register entry + notification | DocumentRegister, Notification | `DocumentApproved` event |
| Project member assigned | Visibility grant | Access engine | `project_members` row |
| Form submission | Any workflow | WorkflowInstance | `Form.workflow_id` |
| Any record overdue | Escalation | Notification chain | `EscalationService` (scheduler) |
| Any expiring entity | Reminder | Notification | `ExpiryService` (scheduler) |
| Timesheet approval | Payroll input | PayrollItem | `PayrollService::collectInputs()` |
| Asset assignment | Employee record | Employee timeline | `ActivityRecorder` |

## 3. Polymorphic hubs

Six polymorphic relations bind the system together. Any record can participate.

| Relation | Table | Morph column | Used by |
|----------|-------|--------------|---------|
| Workflow | `workflow_instances` | `subject` | documents, PRs, expenses, leave, incidents, permits, NCRs, forms, deliverables |
| Tasks | `tasks` | `linked` | projects, documents, meetings, incidents, NCRs, corrective actions, forms |
| Attachments | `attachments` | `attachable` | every record |
| Comments | `comments` | `commentable` | every record |
| Activity | `activity_logs` | `subject` | every record |
| Audit | `audit_logs` | `auditable` | every record |
| Custom fields | `custom_field_values` | `entity` | employees, projects, documents, assets, vendors, HSE, procurement |
| Record ACL | `record_permissions` | `record` | any confidential record |
| Numbering | `number_sequences` | scheme key | projects, docs, PRs, POs, NCRs, incidents |

## 4. Build order implied by dependencies

```
Organization ▸ Users/Employees ▸ Roles/Permissions ▸ Access engine
   ▸ Audit engine ▸ Settings/Config engine
      ▸ Projects ▸ Project teams ▸ Tasks ▸ Notifications ▸ Meetings ▸ Calendar
         ▸ Record engine ▸ Documents ▸ Versions ▸ Workflow engine ▸ Approvals ▸ Signatures
            ▸ Engineering ▸ HSE ▸ QA/QC
               ▸ Procurement ▸ Inventory ▸ Assets ▸ Fleet ▸ Vendors ▸ Clients ▸ Finance
                  ▸ HR ▸ Attendance ▸ Leave ▸ Training ▸ Payroll
                     ▸ Form builder ▸ Custom fields ▸ KPI ▸ Escalation ▸ Expiry ▸ Reports
                        ▸ Executive dashboards ▸ Scheduled reports ▸ Hardening
```

A module may only be started once **every module above it** is functional, because it consumes
their services rather than duplicating them.

## 5. Forbidden dependencies

| Forbidden | Why |
|-----------|-----|
| Engine → domain module | Engines must stay reusable |
| Module A model → Module B controller | Use services/events |
| Any module implementing its own approval table | Workflow engine owns approvals (§78) |
| Any module writing its own audit table | Audit engine owns audit (§59) |
| Any module doing `->get()` without `visibleTo()` | Data-leak risk (§76) |
