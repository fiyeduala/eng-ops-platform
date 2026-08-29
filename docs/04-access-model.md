# 04 — Organization & Access Model (Engine 1)

This is the most security-critical document in the platform. §7, §8, §9, §76 and §94 all reduce to
the model described here.

## 1. Access is a five-gate decision

A user may act on a record only if **every** gate passes.

```
   ┌─────────────────────────────────────────────────────────────┐
   │ GATE 1 · ORGANIZATION                                        │
   │ record.organization_id === user.organization_id               │
   │ Enforced by a global Eloquent scope — cannot be forgotten.    │
   └───────────────────────────┬─────────────────────────────────┘
   ┌───────────────────────────┴─────────────────────────────────┐
   │ GATE 2 · PERMISSION                                          │
   │ Does any role held by the user grant `<module>.<action>`?    │
   └───────────────────────────┬─────────────────────────────────┘
   ┌───────────────────────────┴─────────────────────────────────┐
   │ GATE 3 · SCOPE (the reach of that permission)                │
   │ own | assigned | team | section | department | division |    │
   │ project | organization                                       │
   └───────────────────────────┬─────────────────────────────────┘
   ┌───────────────────────────┴─────────────────────────────────┐
   │ GATE 4 · RECORD ACL                                          │
   │ Explicit grant/deny rows on this specific record.            │
   │ An explicit DENY always wins.                                │
   └───────────────────────────┬─────────────────────────────────┘
   ┌───────────────────────────┴─────────────────────────────────┐
   │ GATE 5 · CONFIDENTIALITY                                     │
   │ public | internal | restricted | confidential                │
   │ Higher levels require ACL membership or owning department.   │
   └─────────────────────────────────────────────────────────────┘
```

Gate 1 and Gate 3 are compiled into **SQL** so that index pages, exports and global search are
filtered by the database. Gates 2, 4 and 5 additionally run per record in policies.

## 2. Organization structure (§3)

Structure is **data**, arbitrarily deep, and not hard-coded.

```
Organization
  └── OrgUnit (self-referencing tree)
        type: division | department | section | team   ← configurable label set
        parent_id, path (materialized), depth, head_employee_id
```

A single `org_units` table with a `type` column and a materialized `path` (`/1/7/23/`) supports:

- Company A: `Engineering › {Civil, Mechanical, Electrical}`
- Company B: `Operations › {Projects, HSE, Procurement, Logistics}`
- Company C: `Projects › {Construction, Engineering, Commercial}`

…with zero code change (§3, §73). Reorganization (§74) is a `parent_id` update plus a path rebuild;
historical records keep the `org_unit_id` they were created with, so history stays truthful.

`OrgUnitType` is itself a configurable table so a company can name its levels
"Directorate / Department / Unit / Crew" if it wishes.

Locations and work sites are a separate `locations` table (not part of the reporting tree) because
a person's site is orthogonal to their department.

## 3. Reporting hierarchy (§86)

Never hard-coded. Two independent mechanisms:

1. **`employees.supervisor_id`** — the direct line manager, an arbitrary-depth chain.
2. **`org_units.head_employee_id`** — the functional head of a unit.

`HierarchyService` answers:

| Question | Method |
|----------|--------|
| Who does X report to? | `chainUpward($employee)` |
| Who reports to X (all levels)? | `subordinates($employee, recursive: true)` |
| Is X above Y? | `isAncestorOf($x, $y)` |
| Who heads the department of X? | `headOf($employee->orgUnit)` |
| Who is the escalation target after N days? | `escalationTarget($rule, $record)` |

Cycles are rejected on write. Depth is capped by configuration to bound recursive queries.

## 4. Roles and grants (§5, §6)

```
roles              organization_id, name, slug, level, is_system, description
permissions        key ('document.approve'), module, action, label      ← global catalogue
permission_role    role_id, permission_id, scope ENUM                   ← the grant + its reach
role_user          role_id, user_id, scope_type NULL|org_unit|project,
                   scope_id, starts_at, ends_at                          ← optionally scoped role
```

Two levels of scoping, and both matter:

- **`permission_role.scope`** — how far this permission reaches by default.
  *"Engineer may view documents, department-wide."*
- **`role_user.scope_type/scope_id`** — restricts an entire role assignment to one unit or project.
  *"Alice is Department Administrator, but only for Engineering."*
  *"Bob is Project Administrator, but only for Project Alpha."*

This is what makes §10 work: an "administrator" is never implicitly unlimited. `Office
Administrator`, `Site Administrator`, `Project Administrator` and `Department Administrator` are
ordinary roles whose grants and scopes differ.

### Effective permission resolution

```php
effective(user) = ⋃ over each active role assignment r:
                    ⋃ over each grant g in r.role.permissions:
                       [ g.permission.key => max_scope( g.scope, constrained_by(r.scope) ) ]
```

The **broadest** scope wins when the same permission arrives from several roles; a role assignment's
own `scope_type/scope_id` **narrows** every grant it carries. Results are cached per user and
invalidated whenever roles, grants or assignments change.

## 5. Access scopes (§7)

| Scope | Rank | Record is visible when… |
|-------|------|-------------------------|
| `own` | 1 | `created_by = user` or `owner_id = user` |
| `assigned` | 2 | user is assignee, reviewer, approver, participant or follower |
| `team` | 3 | `org_unit_id` is the user's team |
| `section` | 4 | `org_unit_id` is within the user's section subtree |
| `department` | 5 | `org_unit_id` is within the user's department subtree |
| `division` | 6 | `org_unit_id` is within the user's division subtree |
| `project` | 7 | `project_id` ∈ projects the user is a member of |
| `organization` | 8 | any record in the organization |

`ScopeCompiler` turns a scope set into one `WHERE (… OR … OR …)` group. Scopes are **additive**:
holding `assigned` and `project` yields the union, not the intersection.

`project` is deliberately not on the same axis as the org-unit ranks — a person can hold
`department` reach for their own department *and* `project` reach for projects they belong to. This
is exactly the §8 scenario.

## 6. Record ACL (Gate 4)

```
record_permissions  organization_id, record_type, record_id,
                    grantee_type (user|role|org_unit|project|project_role),
                    grantee_id, ability (view|edit|approve|sign|download|manage),
                    effect (allow|deny), granted_by, expires_at
```

Rules:

- An `allow` row grants access **beyond** the user's normal scope (sharing a document with one
  person outside the department).
- A `deny` row removes access the user would otherwise have, and **always wins**.
- Rows may expire, which the Expiry engine monitors.

## 7. Confidentiality (Gate 5, §14, §27)

`confidentiality` column on sensitive records:

| Level | Who can see it |
|-------|----------------|
| `public` | anyone in the organization with the base permission |
| `internal` | default; normal scope rules apply |
| `restricted` | owning org-unit subtree + explicit ACL only — **project membership is not enough** |
| `confidential` | explicit ACL only, plus roles holding `<module>.view_confidential` |

This is the rule that makes the §14 requirement true: a Finance record marked
`CONFIDENTIAL — FINANCE ONLY` does **not** become visible merely because the viewer is on the
project. Project membership grants project-context visibility; it never overrides confidentiality.

## 8. Executive visibility (§9)

There are **no security bypasses** — not even for the Managing Director. Executive reach is
expressed as ordinary grants at `organization` scope, seeded into an `Executive Oversight` role
template that an administrator can edit or revoke.

The one exception is the **Super Administrator**, which is a platform-operator flag
(`users.is_super_admin`) rather than a role. It is required to bootstrap an organization, it is
never assignable through the UI to a normal tenant user, and every action it takes is audited and
flagged. It does not exempt anyone from Gate 1 — a super administrator operates inside exactly one
organization context at a time.

A configuration matrix lets each company decide the executive posture (§9):

```
settings.access.md_sees_all          = true|false
settings.access.ceo_sees_all         = true|false
settings.access.hr_scope             = department|organization
settings.access.finance_scope        = department|organization
settings.access.dept_head_sees_projects_of_department = true|false
```

These settings only choose which *grants* are seeded/maintained; enforcement always runs through
the same five gates.

## 9. Multi-tenancy (§75, §76)

Single database, `organization_id` on every tenant-scoped table, with:

- a global Eloquent scope applied by the `BelongsToOrganization` trait,
- automatic `organization_id` population on create,
- a composite index leading with `organization_id` on every scoped table,
- `EnsureOrganizationContext` middleware binding the current organization to the container,
- a test that **fails the build** if any tenant table lacks `organization_id`.

A user from Organization A can never reach Organization B: the global scope makes the query return
zero rows, so an IDOR attempt (`/projects/2`) yields a 404 rather than a leak (§94).

## 10. Worked example — Project Alpha (§8)

Setup: Project Alpha involves Engineering, HSE, Procurement, Finance.
John and Mary (Engineering), David (HSE), Sarah (Procurement), Michael (Finance) are members.

| User | Grants held | Resulting visibility |
|------|-------------|----------------------|
| John | `project.view:project`, `task.view:assigned`, `document.view:department`, `document.view:project` | Project Alpha; his engineering tasks; Engineering documents; project-wide memos, meetings, reports; documents explicitly shared with the project |
| David | `project.view:project`, `hse.*:department`, `task.view:assigned` | Project Alpha; HSE records; HSE tasks; project-wide information; relevant meetings |
| Sarah | `project.view:project`, `procurement.*:department`, `document.view:project` | Project Alpha; procurement records; purchase requests; procurement tasks; relevant documents |
| Michael | `project.view:project`, `finance.view:department` | Project Alpha; finance records he is permitted to see; finance tasks; relevant reports |

None of them gains access to another department's confidential records, because
`document.view:project` reaches only records whose `confidentiality` is `public`/`internal` **and**
whose `project_id` matches — while a Finance record marked `restricted` to the Finance unit fails
Gate 5 for everyone outside Finance.

## 11. Implementation contract

```php
// Every index query, export and search MUST be written as:
Document::visibleTo($user, 'document.view')->paginate();

// Every single-record action MUST be written as:
$this->authorize('approve', $document);   // → DocumentPolicy → ScopedPolicy → AccessResolver
```

`ScopedPolicy` implements all five gates once. A module policy overrides only genuinely
module-specific rules and calls `parent::` for the rest — so no module can accidentally skip a gate.
