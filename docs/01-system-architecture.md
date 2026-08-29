# 01 — System Architecture

## 1. Guiding principle

The platform is **one system**, not a set of CRUD modules. Every module is a thin domain layer on
top of five shared engines. If two modules both need approvals, versioning, audit or visibility,
they consume the *same* engine — they never re-implement it.

```
┌──────────────────────────────────────────────────────────────────────────┐
│                          PRESENTATION                                     │
│   Blade + Tailwind + Alpine.js · Blade components · permission-aware nav  │
└──────────────────────────────────────────────────────────────────────────┘
                                    │
┌──────────────────────────────────────────────────────────────────────────┐
│                        HTTP / APPLICATION                                 │
│  Controllers (thin) · Form Requests (validation) · Policies (authz)       │
│  Middleware: auth · organization context · permission · audit context     │
└──────────────────────────────────────────────────────────────────────────┘
                                    │
┌──────────────────────────────────────────────────────────────────────────┐
│                            DOMAIN                                         │
│  Services · Actions · Events/Listeners · Jobs · Notifications             │
│  Domain modules: Projects · Engineering · HSE · QAQC · Procurement ·      │
│  Inventory · Assets · Fleet · HR · Finance · Meetings · Documents         │
└──────────────────────────────────────────────────────────────────────────┘
                                    │
┌──────────────────────────────────────────────────────────────────────────┐
│                        THE FIVE ENGINES                                   │
│  1 Organization+Access  2 Workflow  3 Record+Document                     │
│  4 Activity+Audit       5 Configuration                                   │
└──────────────────────────────────────────────────────────────────────────┘
                                    │
┌──────────────────────────────────────────────────────────────────────────┐
│                         PERSISTENCE                                       │
│  Eloquent models · global org scope · migrations · indexes                │
│  MySQL/MariaDB · private filesystem disk · database queue · database cache│
└──────────────────────────────────────────────────────────────────────────┘
```

## 2. Engine responsibilities

### Engine 1 — Organization + Access
*Namespace:* `App\Engines\Access`

- Resolves the acting user's **organization context** (global Eloquent scope + middleware).
- Resolves **effective permissions** — the union of permission grants from all the user's roles.
- Resolves each grant's **access scope** (`own → assigned → team → section → department →
  division → project → organization`).
- Compiles scopes into SQL predicates so list queries are filtered **in the database**, never in PHP.
- Evaluates **record-level ACL** and **confidentiality** as an additional gate.

Public surface: `AccessResolver`, `ScopeCompiler`, `VisibilityService`, `Scopeable` trait,
`BelongsToOrganization` trait, base `ScopedPolicy`.

### Engine 2 — Workflow
*Namespace:* `App\Engines\Workflow`

- Administrator-defined workflow **definitions** (stages, assignee rules, conditions, SLAs).
- Runtime **instances** bound polymorphically to any record.
- **Dynamic assignee resolution** — "the Engineering Manager *of this project*", not "John".
- Conditional routing, escalation, rejection/resubmission behaviour.
- Emits approvals into the central **My Approvals** inbox.

Public surface: `WorkflowEngine`, `AssigneeResolver`, `ConditionEvaluator`, `EscalationService`,
`HasWorkflow` trait.

### Engine 3 — Record + Document
*Namespace:* `App\Engines\Record`, `App\Engines\Document`

- Common record concerns: owner, status machine, attachments, comments, followers, numbering,
  custom fields, lifecycle.
- Document specifics: immutable versions/revisions, private storage, transmittals, signatures.

Public surface: `RecordService`, `StatusMachine`, `NumberGenerator`, `DocumentService`,
`VersionService`, `SignatureService`, traits `HasStatus`, `HasAttachments`, `HasComments`,
`HasCustomFields`, `HasNumber`, `HasFollowers`.

### Engine 4 — Activity + Audit
*Namespace:* `App\Engines\Audit`

- `AuditLog` — field-level, append-only, technical: old value / new value / IP / agent.
- `ActivityLog` — human-readable timeline: "Mary uploaded Revision B".
- Written by model observers and explicit service calls; never by controllers.

Public surface: `AuditLogger`, `ActivityRecorder`, `Auditable` trait, `HasActivity` trait.

### Engine 5 — Configuration
*Namespace:* `App\Engines\Config`

- Organization structure, roles, permissions, workflows, statuses, numbering, custom fields,
  forms, templates, categories, escalation rules, KPI definitions, branding, settings.
- All are **data**, editable through the Admin Configuration Center.

Public surface: `SettingsRepository`, `FormBuilderService`, `CustomFieldService`,
`NumberingService`, `StatusRegistry`, `TemplateRenderer`.

## 3. Request lifecycle

```
Request
  → EnsureOrganizationContext        (binds Organization to container, sets audit context)
  → auth / verified                  (authentication)
  → RecordAuditContext               (IP, user agent, route)
  → Controller
      → FormRequest                  (validation + authorize())
      → Policy check via Gate        (Engine 1)
      → Service / Action             (domain logic)
          → StatusMachine            (Engine 3 — legal transition?)
          → WorkflowEngine           (Engine 2 — advance / assign)
          → Events dispatched
      → AuditLogger + ActivityRecorder (Engine 4, via observers/listeners)
      → NotificationDispatcher       (authorization-filtered recipients)
  → Blade view (permission-aware components)
```

## 4. Layering rules

| Rule | Rationale |
|------|-----------|
| Controllers contain no business logic | Testability; reuse from jobs/console |
| All list queries pass through `visibleTo()` | Prevents data leaks by omission |
| Policies never hard-code role names | Roles are org-configurable data |
| No `Auth::user()` inside services | Services take an explicit actor argument |
| Status changes only via `StatusMachine` | Prevents forged status submission (§63) |
| File access only via signed controller | No public path to private storage (§67) |
| Notifications only via `NotificationDispatcher` | Guarantees authorization filtering (§68) |

## 5. Infrastructure constraints (V1 / cPanel)

**Required:** PHP 8.2+, MySQL/MariaDB, Composer, cron, SMTP, SSL, Laravel private storage.

**Deliberately not required:** Redis, WebSockets, Docker, Supervisor, Node runtime, Elasticsearch.

| Concern | V1 (cPanel) | Optional later (VPS) |
|---------|-------------|----------------------|
| Queue | `database` driver, cron-driven worker | Redis + Supervisor |
| Cache | `database` driver | Redis |
| Session | `database` driver | Redis |
| Search | MySQL `FULLTEXT` + indexed columns | Meilisearch / Elastic |
| Realtime | Poll + web push | Reverb / Pusher WebSockets |
| Scheduler | cPanel cron → `schedule:run` | systemd timer |
| Assets | Pre-built Vite output committed/deployed | CI build pipeline |

Because every one of these is a Laravel **driver swap**, moving to a VPS changes configuration —
not business logic (§81).

## 6. Directory layout

```
app/
  Engines/
    Access/       Resolver, ScopeCompiler, VisibilityService, Enums, Traits
    Workflow/     WorkflowEngine, AssigneeResolver, ConditionEvaluator, Escalation
    Record/       RecordService, StatusMachine, NumberGenerator, Traits
    Document/     DocumentService, VersionService, SignatureService, Storage
    Audit/        AuditLogger, ActivityRecorder, Traits
    Config/       Settings, CustomFields, Forms, Templates, Numbering
  Models/         Domain models grouped by module namespace
  Policies/       ScopedPolicy base + per-model policies
  Services/       Domain services per module
  Actions/        Single-purpose invokable use cases
  Http/
    Controllers/  Grouped per module
    Requests/
    Middleware/
    ViewModels/
  Events/  Listeners/  Jobs/  Notifications/  Observers/
  Support/        Enums, helpers, value objects
resources/views/
  layouts/  components/  <module>/
docs/
database/migrations/  database/seeders/  database/factories/
tests/Feature/  tests/Unit/
```

## 7. Non-goals for V1

- Full double-entry accounting (§46) — operational finance only.
- Country-specific statutory payroll tax rules (§47) — payroll components are configurable data.
- Native mobile apps (§70) — mobile-first responsive Blade, PWA-ready.
- Recreating chat (§71) — contextual communication attached to records only.
