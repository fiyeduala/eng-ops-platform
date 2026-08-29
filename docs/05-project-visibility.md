# 05 — Project Visibility Model

## 1. Two independent visibility axes

A user's sight of a record is the **union** of two axes, gated by confidentiality.

```
        ORG AXIS                            PROJECT AXIS
  "what my department does"            "what my project does"
  org_unit subtree reach               project_members reach
        │                                     │
        └──────────────┬──────────────────────┘
                       ∪  (union)
                       │
              CONFIDENTIALITY GATE
                       │
                  visible set
```

Being on a project never widens the org axis, and belonging to a department never widens the
project axis. This is what lets John see *Engineering documents anywhere* **and** *Project Alpha
information*, without seeing *Finance documents on Project Alpha* (§8, §14).

## 2. Record visibility classes

Every project-attached record declares a `visibility` value:

| Value | Meaning | Who sees it |
|-------|---------|-------------|
| `project_wide` | Belongs to the whole project | Every active `project_member` with `project.view` |
| `project_department` | Owned by one department on the project | Members whose `org_unit` is in that subtree, plus the PM/Project Director |
| `project_team` | Owned by a named project team | Members of that team |
| `participants` | Only named participants | Users listed in `record_permissions` / assignees |
| `restricted` | Department-only, project membership is irrelevant | Owning `org_unit` subtree + explicit ACL |
| `confidential` | Explicit ACL only | ACL grantees + `<module>.view_confidential` holders |

## 3. What "project-wide" includes (§14)

Project announcements · project memos addressed to the project · meetings the user is invited to or
that are marked project-wide · general project status, progress and milestones · published project
reports · general project documents · tasks assigned to the user · project activity timeline
entries the user may see.

## 4. What project membership never grants

- Another department's `restricted` or `confidential` records.
- Financial detail beyond what a `finance.*` grant allows.
- HR/personnel records of other members.
- Administrative configuration of the project (needs `project.manage`).
- Approval authority (approval comes from workflow assignment, not membership).

## 5. Membership resolution

```php
// Cached per user per request.
projectIdsFor($user) = project_members
    ->where('user_id', $user->id)
    ->where('is_active', true)
    ->whereDate('starts_on', '<=', today())
    ->where(fn($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', today()))
    ->pluck('project_id');
```

Membership is **time-bounded** (§12): a consultant assigned for three months loses visibility
automatically when `ends_on` passes — no manual revocation needed, and historical audit entries
remain intact.

## 6. Department assignment cascade (§13)

```
Project Manager assigns DEPARTMENTS to a project
        ↓  creates project_departments rows
Department Head sees "projects assigned to my department"
        ↓  Head assigns individual PERSONNEL within their permission scope
        ↓  creates project_members rows
Individual gains project-axis visibility
```

A department head may only add members from their own org-unit subtree — enforced in
`ProjectMemberPolicy`, not in the UI. Attempting to add someone outside the subtree returns 403 even
if the form is tampered with.

## 7. Multi-project people (§4)

A person's primary org unit is unchanged by project work.

```
John  ·  Department: Engineering  ·  Position: Mechanical Engineer
      ├─ Project A → project_role: Mechanical Engineer
      └─ Project C → project_role: Technical Lead
```

`project_members.project_role_id` is independent of `employees.position_id`, so John can hold
authority on Project C that he does not hold on Project A. Workflow assignee rules of type
`project_role` resolve against this per-project role — which is why "send to the Technical Lead of
this project" routes correctly on C and not on A.

## 8. Executive drill-down (§89)

The drill-down path preserves context by carrying scope through the URL and re-checking
authorization at every level:

```
/dashboard                       → organization rollup
/projects/{p}                    → project command center
/projects/{p}/departments/{u}    → department slice of that project
/projects/{p}/documents/{d}      → the record
/documents/{d}/workflow          → "Awaiting Engineering Manager"
```

Every level runs the same five gates. A CEO reaches the leaf because their grants are
`organization`-scoped — not because drill-down bypasses anything.

## 9. Test obligations

| Test | Assertion |
|------|-----------|
| Project member index | Non-member gets 403/404 on `/projects/{id}` |
| Cross-department leak | Sarah (Procurement) cannot open a `restricted` Finance record on her project |
| Expired membership | Member with `ends_on` yesterday loses project-axis records |
| Department head bounds | Head cannot add a member from outside their subtree |
| Confidential vs project | `confidential` record stays invisible to all project members lacking ACL |
| Search parity | Global search returns exactly the same set as the index pages (§61) |
