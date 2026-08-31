# 14 — Development Roadmap

## Method

Each phase is built with the full vertical slice per module (§100):

> migration → model + relationships → policy → service/action → form request → controller →
> routes → views → notifications → events → tests → seeders

A phase is not "done" until its tests pass and its authorization tests (including IDOR attempts)
pass. No phase starts before the phase it depends on is verified end-to-end.

---

## PHASE 1 — Foundation ✅

Laravel skeleton · authentication · organizations · users · employees · org units (configurable
tree) · positions · locations · roles · permissions · access scopes · `ScopedPolicy` ·
`AccessResolver` · `ScopeCompiler` · settings · audit engine · activity engine · base UI shell
(sidebar, breadcrumbs, permission-aware navigation, Tailwind design system).

**Exit criteria:** a user in Org A cannot see any record of Org B; permission + scope resolution is
unit-tested; every write is audited.

**Met.** 80 tests, 418 assertions. `TenantIsolationTest` covers the boundary including search
(§61); `ScopeAlgebraTest`, `ScopeResolutionTest` and `RecordAclTest` cover resolution, and assert
that the policy and the compiled list agree on every record; `TenantScopeCoverageTest` reads the
schema so a later phase cannot add an unscoped table without failing the build; `Phase1SmokeTest`
requests every authenticated route three ways — permitted, unpermitted (403), anonymous.

## PHASE 2 — Core operations ✅

Projects ✅ · project types ✅ · project departments ✅ · project members ✅ · project roles ✅ ·
activity timeline UI ✅ (project page) · tasks (+ checklists, dependencies, followers) ✅ ·
notification engine ✅ · memos ✅ · announcements ✅ · meetings ✅ ·
meeting minutes/decisions/actions ✅ · calendar ✅.

**Exit criteria:** the §8 Project Alpha scenario passes as a feature test for all five users.

**Met.** 206 tests, 1,110 assertions across the suite. `ProjectAlphaTest` (15 tests) runs the
scenario's project layer for John, Mary, David,
Sarah and Michael: five people from five departments reaching one project through the project axis
alone, with Alpha owned by a unit none of their departments contains so the org axis cannot rescue
them. It also covers each edge docs/05 §9 asks for — the non-member's 403, Sarah refused a
`restricted` Finance project she is a member of, membership expiring by `ends_on` with nobody
revoking anything, a department head bounded to their own subtree while a project manager is not,
the `/projects/{a}/team/{member-of-b}` IDOR, and the cross-tenant 404 — plus the split between
`project.view` and `project.view_financials`, and status moves as a whitelist rather than a field.

Two defects it caught, both now fixed: activity rows about a project itself carried no `project_id`,
so a project's own status history was missing from the only timeline the project page renders
(`ActivityRecorder` now derives it through `accessProjectColumn()`); and the add forms have no
Active checkbox, so `normaliseBooleans` was writing `is_active = false` on every create — a member
row that listed on the roster and granted nothing.

**Tasks.** `TaskAccessTest` (18 tests) covers the task half of §8: three people reaching one task by
three different routes (assignee, management chain, project team) and each axis reaching only what
it should; assignment treated as a grant rather than an edit, on both the policy and the form
(`assignment cannot ride in on the edit form`); `complete` and `verify` held apart, so the doer
finishes the work and somebody else accepts it; dependency cycles and terminal moves blocked by open
predecessors; the `/tasks/{a}/checklist/{item-of-b}` and `/tasks/{a}/dependencies/{edge-of-b}` IDOR
attempts; a blocking task the actor cannot see refused rather than named; and following a task
granting nothing.

`DemoSeederTest` (14 tests) then runs `DemoSeeder` end to end and asserts the same properties against
the data an evaluator actually opens: both companies separate, every task carrying its own tenant's
status rows, a transmittal genuinely blocked by a report still in review, a `restricted` commercial
task hidden from the engineers on its own project, and a membership that lapsed with the job it
belonged to.

One defect that pass caught, now fixed: six seeded role templates — project manager, project
director, engineering manager, lead engineer, project administrator, department administrator —
granted `task.manage` or `task.assign` with no `task.view`. There is no implication rule between
abilities (deliberately), and the baseline grants only `task.view: assigned`, so a project manager
could reassign and close the work on their own job while the task register showed them nothing but
their own two tasks.

**Notifications.** `NotificationDispatchTest` (17 tests) covers docs/10 §8 in full, plus four the
document did not ask for: an audience that cannot cross a tenant boundary, a causer who is not told
what they just did, a disabled account dropped before a row is written, and the
`/notifications/{id-of-somebody-else}` IDOR. The two that matter most are the pair around
revocation — a notification is a durable copy of something a record said, so the list re-checks every
row's subject at read time and the follow-through link re-checks again at the moment of the click.
A row that no longer passes is removed rather than greyed out; "there is something here you may not
see" is itself a disclosure. Deviations from docs/10 are recorded in its §9 rather than left for the
next phase to find: push and digest are stored but not transported, de-duplication is not yet
implemented, and listeners are synchronous in V1 because a queued event would deserialize its models
on a worker with no tenant bound, where `OrganizationScope` fails closed.

One defect that pass caught, now fixed: Laravel's automatic listener discovery is on by default even
when `bootstrap/app.php` never calls `withEvents()`, so `NotifyTaskAssignee` was registered twice and
every assignment sent two notifications. `withEvents(discover: false)` now makes the map in
`AppServiceProvider` the only registration — a file rename must not be able to change who gets told
what.

**Communications.** 62 tests across `MemoAccessTest` (20), `MeetingAccessTest` (17),
`AnnouncementAccessTest` (15) and `CalendarAccessTest` (10). The three modules share the five gates
and the status machine rather than re-implementing distribution, so the tests concentrate on the
places where "who was told" and "who may read" come apart. A memo's distribution list is a grant:
addressing somebody writes a recipient row, but the record ACL row is written only when the memo is
actually issued, so a draft addressed to fifteen people is readable by none of them. That is what
lets a `confidential` memo work at all — no role holds `memo.view_confidential`, so the issued
distribution is the only route in, and a director with organization-wide `memo.view` who is not on
the list still gets nothing. Withdrawal keeps the record: the reason, the recipients and their
`read_at` stamps all survive, because a memo people acted on is evidence.

Meetings add a field-level rule the scope engine cannot express. Minutes are hidden inside a meeting
the reader may otherwise see in full, until they are circulated — `Meeting::minutesVisibleTo()`
answers for the minute-taker, the organiser, the creator and anyone holding `meeting.circulate`, so
recording and releasing are separate acts by separate people. Actions raised from a meeting are
ordinary tasks with `linked_type`/`linked_id` back to it, carrying the task engine's own
authorization rather than a private copy, which is §90 in the one place it is easiest to violate.
The calendar is a read projection over meetings, tasks and announcements: it re-asks each source's
own `visibleTo` scope per band rather than trusting a date range, so nothing appears on a month grid
that would 403 when clicked.

`DemoSeeder` seeds all of this through the services rather than inserting rows, so the demo cannot
hold a state the application would refuse to produce — including a memo drafted and never issued, a
memo issued then withdrawn a fortnight later, minutes recorded but deliberately not circulated, and a
progress meeting whose decision became a real assigned task. Seven further `DemoSeederTest` cases pin
those claims, so the demonstration data cannot quietly stop demonstrating them.

One defect that pass caught, now fixed: sixteen grants were missing across ten role templates —
secretaries who could record minutes but not circulate them, administrators who could convene a
meeting but not see the register it appeared in. The seeder found them because writing realistic data
through the real services requires the seeded people to genuinely hold the permissions their jobs
imply, which hand-built fixtures never force you to check.

**Not yet covered:** the document, HSE and procurement halves of §8. Those grants
(`document.view`, `hse.*`, `procurement.*`, `finance.view`) belong to Phases 3–5, and the assertions
attach to the same test file as each module lands.

## PHASE 3 — Documents & workflow ✅

Document management ✅ · categories/registers ✅ · versions/revisions ✅ · private storage +
authorizing download ✅ · workflow engine (definitions, stages, conditions, dynamic assignees,
SLA) ✅ · approval engine ✅ · My Approvals inbox ✅ · signatures ✅ · transmittals ✅ ·
templates ✅ · PDF generation ✅ · native editor + .docx round trip ✅.

**Exit criteria:** a workflow defined entirely through the UI routes a document through
Engineer → Lead Engineer → Engineering Manager → Project Manager, with escalation, rejection and
resubmission, and no approved revision is ever overwritten.

**Met.** 288 tests, 1,771 assertions across the suite. `WorkflowEngineTest` builds the route by
POSTing to the same endpoints the builder screen posts to — no factories, no service calls — points
a register at it, and walks a real document through both stages. If any part of defining a route
required code, that test would not compile.

Alongside the exit criterion it pins the ways a configurable approval system silently goes wrong:
a published definition is never mutated in place (editing produces a new version; documents already
travelling the old one keep travelling it) · assignees are frozen at stage entry, so a
reorganization mid-approval cannot rewrite who was required · a stage that resolves to nobody
**stalls** rather than being skipped or auto-approved, because a route that cannot find its approver
and moves on has produced a document marked approved that nobody approved · publishing validates ·
a role stage stores the role *id*, not its slug (a regression that would have saved cleanly,
displayed correctly, and resolved to nobody the first time a document actually travelled it).

**Beyond the brief, on the user's instruction that documents should "replace MS Word in a sense or
integrate with it":** documents are authored natively in the browser (TipTap → sanitized HTML +
JSON on the version row) with check-out locking, .docx import that keeps the original file, and
.docx/PDF export whose title block comes from the company's own Template. docs/09 §10 states the
boundary: this is not byte-faithful Word interoperability, and tracked changes, embedded objects and
exact pagination do not survive a round trip. Real-time co-editing is out of V1 — it needs a
WebSocket server, which §1 bars from being mandatory on cPanel.

**Four defects the demo seeder caught**, all fixed, none of which a fixture-built test would have
found because each needed a whole company's worth of real configuration:

- `requires_approval` on a register was a checkbox the screens explained and nothing read. Finding
  no route on the category, the engine fell through to the tenant's **default** workflow and sent
  correspondence received from clients out for technical review. `Document::requiresWorkflow()` now
  distinguishes "no preference, use the default" from "do not route this at all" (docs/09 §7).
- `TemplateRenderer` resolved `{{user.job_title}}` against a column that does not exist — job titles
  live on `positions` — so every rendered letter quietly omitted the signatory's title. Its three
  relation indirections also lazy-loaded, which throws outside production and silently costs a query
  per placeholder inside it.
- Kestrel's starter route named the `engineering-manager` role, which exists as a template in every
  tenant but has no holder in an eight-person facilities-management firm: the stage would resolve to
  nobody and stall. Rather than special-casing the seeder, Kestrel's administrator **edits the route
  through the same service the UI calls** — which is the §73 configurability claim demonstrated on
  the second tenant rather than asserted.
- The signature panel on a revision page had never rendered, because nothing ever wrote a signature.
  The table, the model, the policy, the morph key, the relations, `DocumentVersion::contentHash()`
  documented as "the bytes a signature is taken over" and a finished Blade panel all existed; the
  write path did not, and only a seeded route that actually reached a decision made the absence
  visible. `RecordDocumentSignature` now listens for `ApprovalDecided` — in the document module, not
  the engine, which names no module — and is registered **before** the notification listener so a
  failure to record the evidence rolls the decision back with it.

**Known limits, stated rather than discovered:** revision labels follow the register's configured
scheme, so a numeric register issues its first approved revision as **Rev 0** (the EPC convention),
not Rev 1 · signatures are internal approval signatures, not qualified ones under eIDAS or ESIGN
(docs/09 §5) · the TipTap bundle is ~564 kB minified and is loaded on every page; code-splitting it
behind the editor routes is a Phase 9 performance item, not a correctness one.

## PHASE 4 — Engineering · HSE · QA/QC ⬜

Disciplines · deliverables · drawing register · design reviews · RFIs · technical queries ·
submittals · HSE incidents/near misses/observations · risk assessments · inspections · toolbox
talks · permits-to-work · corrective actions · HSE training · quality inspections · NCR lifecycle.

**Exit criteria:** an HSE incident automatically spawns an investigation and corrective-action task
through configuration alone.

## PHASE 5 — Business operations ⬜

Vendors · clients · contracts · purchase requests · RFQs · quotations · evaluation · purchase orders
· goods receipt · warehouses · inventory · stock ledger · material requests · assets · asset
maintenance · fleet · fuel · vehicle documents · finance (income, expenses, petty cash, budgets,
cost centers, project costs, payments).

**Exit criteria:** PR → approval → PO → goods receipt → stock transaction → project cost posts as
one connected chain (§90).

## PHASE 6 — People ⬜

Employee records · recruitment · onboarding · attendance (mobile clock in/out) · timesheets ·
overtime · leave types and requests · training · performance · promotions · transfers ·
disciplinary · exit · payroll components and runs · visitor management.

**Exit criteria:** leave and timesheet approvals run on the *same* workflow engine as documents.

## PHASE 7 — Advanced administration ⬜

Form builder (all field types, repeating tables, signatures) · form → workflow binding · custom
fields · configurable statuses and transitions · workflow builder UI · numbering schemes · KPI
definitions · escalation rules · expiry watches · report builder · Admin Configuration Center.

**Exit criteria:** the §73 "configure a new company" and §74 "reorganization" walkthroughs are
completed entirely through the UI by a non-developer.

## PHASE 8 — Executive intelligence ⬜

Executive command center · department dashboards · project command center · drill-down navigation ·
daily executive report (scheduled, per-recipient visibility) · KPI reports · exports · global
search.

**Exit criteria:** the §89 drill-down path works end to end and search returns exactly the index-page
result set.

## PHASE 9 — Hardening ⬜

Security review · full authorization test matrix (IDOR, scope escape, notification leakage, search
leakage, file access) · N+1 and query-count budgets · load sanity check · error handling and
friendly error pages · backup and restore drill · deployment runbook · demo seed data · user
documentation.

**Exit criteria:** the authorization test matrix is green; a restore drill is documented with a
measured RTO.

---

## Cross-phase, always-on

| Discipline | Rule |
|------------|------|
| Security | every new route ships with a policy and an authorization test |
| Audit | every state change writes audit + activity entries |
| Configurability | if a value differs between companies, it is data, not code |
| Integration | a new module must consume the engines, never fork them |
| Performance | no unbounded queries; eager-load everything a view touches |
| Mobile | every field-facing screen is verified at 375px width |

## Priority order when trade-offs arise (§100)

1. Security · 2. Authorization · 3. Data integrity · 4. Workflow correctness · 5. Configurability ·
6. Maintainability · 7. Usability · 8. Performance · 9. Visual polish
