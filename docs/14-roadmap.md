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

## PHASE 4 — Engineering · HSE · QA/QC ✅

Disciplines · deliverables · drawing register · design reviews · RFIs · technical queries ·
submittals · HSE incidents/near misses/observations · risk assessments · inspections · toolbox
talks · permits-to-work · corrective actions · HSE training · quality inspections · NCR lifecycle.

**Exit criteria:** an HSE incident automatically spawns an investigation and corrective-action task
through configuration alone.

**Met, and proven by `tests/Feature/Hse/IncidentSpawnsInvestigationTest`.** The test configures the
entire route by POSTing to `workflows.store`, `workflows.stages.store` and `workflows.publish` —
the same three endpoints the builder screen posts to, with the same field names its inputs carry. It
calls no service and constructs no stage. A site safety officer then reports an incident through the
ordinary register form and submits it; the investigation opens with the incident's project and
classification and the configured lead and due date, and the corrective action is raised, assigned
and dated. Neither the engine nor the controller names either record type.

The mechanism is the `create_record` auto-action plus `SpawnsFromRecord` — see docs/07 §6. The
runner resolves a morph key through the morph map and asks the class; the builder's picker is
discovered the same way. Adding a spawnable record type in Phase 5 is one interface on one model.

**The exit criterion is in the demo data, not only in the test.** `DemoSeeder` configures Meridian's
incident route through `WorkflowDefinitionService` — one stage, "HSE manager review", assigned to
the `hse-manager` role, carrying two `create_record` actions — reports a lost-time injury against
it, and then FETCHES the investigation and the corrective action back out of the database, throwing
a `RuntimeException` if either is missing rather than seeding a thinner story quietly. Seeding
produces `INC-2026-0002 → INV-2026-0001`, lead resolved from the role and due in fourteen days, plus
`CA-00004` on the site manager due in seven. Kestrel configures no incident route at all and closes
its near miss by hand — the same seeder code, a different configuration (§73).

The seeder's `decide()` helper is now typed to `Model` rather than `Document`, which is Engine 2's
argument in one signature: the demo approves an incident with the call it approves a drawing
register submission with, because the engine never knew which it had.

**Four defects this phase found**, each invisible to a unit test because each lived in the seam
between a form, a request and an engine:

- **`WorkflowStageRequest` validated every auto-action key except `record`.** `validated()` returns
  only what the rules name, so the record type was stripped between the builder and the stage. The
  stage saved, the screen said it saved, the summary listed the action — and the runner threw "a
  create_record action named no record type" on the first incident that reached the stage.
  Configuration that silently discards half of itself is worse than configuration that refuses.
- **Nothing outside `DocumentService` ever called `WorkflowEngine::start()`.** The engine was
  general and its single caller was not, so no register record could enter a route at all — the exit
  criterion was unreachable through the UI no matter how the workflow was configured (§90). The fix
  is `RegisterController::submitForApproval()`, shared by every register whose model uses
  `HasWorkflow`, gated on `update` rather than a new permission nobody knew to grant.
- **`Incident::correctiveActions()` declared `HasMany` and returned a `MorphMany`**, and
  `IncidentPerson` had no `$table`, so Laravel's pluraliser looked for `incident_people` against a
  table called `incident_persons`. Both are fatal the moment an incident page has anything on it;
  both went unnoticed because no test had opened a fully populated incident.
- **`RegisterService::create()` left `confidentiality` to the column default.** The row was always
  right, so every test that reloaded a record passed; the object the service handed back carried no
  such key at all. Two things read the object rather than the row — the notification gate, which
  skips a subject whose classification it cannot see (§68), and `SubmittalService::resubmit()`,
  which copies the parent's classification onto the next attempt. The second copied a null onto a
  NOT NULL column and took the whole resubmittal down, but only for callers that never reload: a
  seeder, an import, a queued job. Through HTTP, route model binding hid it completely. The
  classification is now stamped on the model at creation, column-gated and cached per table.

**Idempotence is tested separately and is not a detail.** A stage is re-entered every time a report
is sent back and resubmitted, and each entry runs its auto-actions again. The test sends the
incident back with `request_revision`, resubmits it, and asserts the incident still has exactly one
investigation and one corrective action — the same rows, not replacements. That check lives in
`Investigation::spawnFrom()`, where "one per incident" is a fact about investigations, rather than in
an engine that names no module.

**Blade's `\B@` rule cost three broken pages** before a compile pass over the whole view tree caught
them. Blade does not compile a directive immediately preceded by a word character, but it does
compile the matching `@endif`: `the promised date@endif` left an `@if` open and `owes the answer@if`
left a stray `@endif` behind, each producing a parse error at a line number nowhere near the cause.
Every directive now starts after whitespace or punctuation, and `resources/views` compiles clean
(226 templates).

**Known limits, stated rather than discovered:** the `create_record` picker offers only record types
that implement `SpawnsFromRecord` — currently investigations and corrective actions — so "open a
permit when an inspection fails" is a Phase 5 interface, not a configuration option today · a
corrective action's idempotence key is its configured title, so two actions with the same title on
one stage collapse into one · `submitForApproval()` is wired to a route on incidents only; the other
registers inherit the method but have no submit endpoint until a company asks for one.

## PHASE 5 — Business operations ✅

Vendors ✅ · clients ✅ · contracts ✅ · purchase requests ✅ · RFQs ✅ · quotations ✅ · evaluation ✅ ·
purchase orders ✅ · goods receipt ✅ · warehouses ✅ · inventory ✅ · stock ledger ✅ · material
requests ✅ · assets ✅ · asset maintenance ✅ · fleet ✅ · fuel ✅ · vehicle documents ✅ · finance
(tax codes ✅, income ✅, expenses ✅, petty cash ✅, budgets ✅, cost centers ✅, project costs ✅,
payments ✅).

**Exit criteria:** PR → approval → PO → goods receipt → stock transaction → project cost posts as
one connected chain (§90). **Met**, and pinned end to end by `ProjectCostTest`.

**Delivered (completed 2026-09-17):**

- **Part 1 — commercial:** vendors, clients and contracts (`e774cee`).
- **Part 2 — procurement:** purchase requests, RFQs, quotations/evaluation, purchase orders and
  goods receipt (`434de06`).
- **Part 3 — inventory:** warehouses, catalogue, stock ledger and material requests (`6bcb0c3`).
  Receipt acceptance posts accepted, catalogued goods to the selected warehouse and carries the
  purchase order's project attribution.
- **Part 4 — assets, fleet, project costs and budgets** (`ad0a976`): custody history, maintenance,
  vehicles, fuel logs and vehicle documents; immutable, source-linked project costs posted on
  receipt acceptance in purchase and organization currency; cost centers and controlled budgets
  whose actuals are read live from the cost ledger.
- **Part 5 — operational finance:**
  - **Tax codes** are company data (§47): rate, sales/purchases/both, recoverable or not. The rate
    and recoverability are copied onto each record when it is saved, so changing a code never
    rewrites an issued invoice or an approved claim. A code in use cannot be deleted.
  - **Expenses:** draft → submitted → approved → paid, with return-for-amendment (back to an
    editable draft) and rejection, both requiring a reason. Tax and totals are computed on the
    server. Approval posts a project cost (net when tax is recoverable, gross when not). The
    claimant cannot approve their own claim unless the company waives
    `finance.separate_claimant_and_approver`; a waiver is recorded on the activity entry.
  - **Income:** draft → issued → part received → received, or cancelled with a reason. An income
    raised against a client contract takes its client and project from the contract. The two
    received statuses are never chosen by a person; they follow from recorded receipts.
  - **Payments** against a purchase order, an approved expense or an issued income. Outgoing:
    draft → submitted → approved → paid, and the approver cannot release the money unless
    `finance.separate_payment_approver_and_payer` is waived (the waiver is recorded). Incoming
    receipts are recorded as paid immediately. Payee, currency, project and confidentiality are
    copied from what is paid. No payable can be over-committed: drafts and approvals count against
    the outstanding balance, under a row lock.
  - **Petty cash:** tins with a custodian, an optional float ceiling and an append-only voucher
    ledger. Balance is a cache of the ledger. Disbursements post project costs; an optional
    `petty_cash.single_disbursement_limit` sends larger payouts to an expense claim. Mistakes are
    reversed once, by their own voucher, which also reverses the cost. Closed tins move no cash.
  - Custodians reach their own tin at `assigned` scope; claimants reach claims typed in on their
    behalf at `own` scope.

See [16-development-handoff.md](16-development-handoff.md) for review findings, validation results
and deployment notes. The earlier Phase 1–4 test totals above are historical results.

## PHASE 6 — People ✅

Employee records ✅ · recruitment ✅ · onboarding ✅ · attendance (mobile clock in/out) ✅ ·
timesheets ✅ · overtime ✅ · leave types and requests ✅ · training ✅ · performance ✅ ·
promotions ✅ · transfers ✅ · disciplinary ✅ · exit ✅ · payroll components and runs ✅ · visitor
management ✅.

**Exit criteria:** leave and timesheet approvals run on the *same* workflow engine as documents.
**Met, and widened.** Not one of the ten registers added in this phase has an approve route: no
`approve()` on any service, no approve or reject endpoint on any controller, and every routed
register decided at `approvals.decide` on the engine that decides a drawing.
`DemoSeederTest::no_register_in_this_phase_decides_itself()` asserts it for the set rather than for
one module, so an eleventh register that grew its own approval path would fail there even if its
author wrote no test at all.

**Delivered (completed 2026-09-18):**

- **Part 1 — leave** (`327c896`): leave types and balances as company data, entitlement accrual,
  holidays and working patterns per organization (§47), requests routed to the engine.
- **Part 2 — attendance and timesheets** (`34a18bf`): mobile clock in/out against geofenced
  locations, timesheet periods, overtime requests.
- **Part 3 — recruitment, onboarding and exit** (`99a7281`): vacancies, applicants, onboarding
  checklists, and exits that cannot clear while an asset or a petty cash tin is still on the
  leaver's name.
- **Part 4 — training, performance, movements and conduct** (`0d425f5`): courses, requirements and
  expiry, appraisal rounds, promotions and transfers applied by a dated sweep, disciplinary cases.
- **Part 5 — payroll** (`27a1687`): pay components, structures and salary history, payroll runs and
  payslips. Two countries, two currencies, two sets of tax bands and **one calculator** — no
  country-specific rule is in the code (§47).
- **Part 6 — visitors and close-out**: the visitor book, the roll call, and the phase-wide exit
  assertion.

**The visitor book is the phase's one stated exception, and it is stated in code.** It is the only
register here that is not workflow-routed: `Visitor::requiresWorkflow()` returns false, there is no
`visitors.submit` route and no transition rows in `StatusCatalogue`. The reason is that a
receptionist with somebody standing at the desk cannot wait on an inbox, so the control is a
permission — `visitor.check_in`, held by the desk and deliberately not by the engineers who book
visitors. The decision is written into the model, the service, the policy, the controller and the
route file so it cannot later read as an oversight, and `VisitorTest` asserts the absence of both
the approve route *and* the submit route rather than skipping the question.

**Two design points in the visitor register that are load-bearing:**

- **The roll call counts heads, not rows,** and is behind `visitor.view` rather than
  `visitor.manage`. A delegation of six arrives on one record; a screen counting rows would be six
  people short at an assembly point. An administrative permission between somebody and the list of
  who is still inside would be a security control that gets people hurt.
- **Visits that were never signed out are closed by an hourly sweep and *labelled*.** `auto_closed`
  is true, the actor is nobody, and the timeline says nobody witnessed the departure. Without the
  sweep the roll call still shows last Tuesday's visitors and within a fortnight nobody believes it;
  without the label the platform would be asserting it saw somebody leave when it did not. The
  cut-off is per organization, which is why the schedule is hourly rather than daily.

**Two defects caught by reading the engine rather than by testing**, both of which would have been
silent in production:

- `Visitor::accessOwnerColumns()` first returned `host_employee_id`. `ScopeCompiler::applyOwn()`
  compares owner columns against `$user->id`, so an employee id there would have matched the wrong
  people or nobody at all — an authorization bug with no symptom. The table now carries
  `host_user_id` alongside the employee id, written together by `VisitorService::syncHostUser()` so
  they cannot drift, and the `accessAssigneeColumns()` override naming `escort_employee_id` was
  removed for the identical reason.
- The end-of-day sweep originally compared against *today's* cut-off. With a 23:00 cut-off and an
  hourly schedule, every run between midnight and 22:59 would have found the cut-off still ahead and
  done nothing, leaving the previous evening's visitors on the roll call for a further full day —
  precisely the failure the sweep exists to prevent. `lastCutOffBefore()` steps back a day when
  today's has not arrived.

## PHASE 7 — Advanced administration ✅

Admin Configuration Center ✅ · configurable statuses and transitions ✅ · numbering schemes ✅ ·
option lists ✅ · custom fields ✅ · form builder (all field types, repeating tables, signatures) ✅ ·
form → workflow binding ✅ · expiry watches ✅ · KPI definitions ✅ · report builder ✅ · saved
views ✅ · escalation rules ✅ (see the note below — deliberately not a new editor).

**Exit criteria:** the §73 "configure a new company" and §74 "reorganization" walkthroughs are
completed entirely through the UI by a non-developer. **Met per surface, with one gap recorded
below.** Every configuration surface this phase added is reachable from the Configuration Center,
built from permissions (§96), and has a test that drives it through the same HTTP endpoints the
screens post to — `FormBuilderTest::a_form_can_be_built_and_published_entirely_through_the_screen`,
`ReportAccessSweepTest::a_report_and_a_shared_view_can_be_set_up_entirely_through_the_screens`, and
`WorkflowEngineTest::a_workflow_defined_entirely_through_the_ui_routes_a_document_through_approval`.
**The gap:** there is no single spanning test that configures a whole new company end to end, nor
one that reorganizes a department tree and re-checks reach afterwards. Each piece is proven; the
walkthrough as one continuous act is not. It belongs in the Phase 9 authorization matrix and is
listed there.

**Delivered (completed 2026-09-20):**

- **Part 1 — the Configuration Center and the state machine** (`b9e7469`): the hub, plus the status
  vocabulary and the transition whitelist as things a company edits. `is_system` records, at the
  only point where it is still known, which statuses the code depends on — a company may relabel,
  recolour and reorder them, and may not rekey or deactivate them. A move into a status an approval
  confers must name a permission, or the generic status endpoints would hand out the outcome the
  approval existed to confer with the engine never involved.
- **Part 2 — numbering, vocabulary and invented fields** (`2d273af`): gapless, collision-free
  numbering allocated inside the record's own transaction from a locked row (§64); option lists that
  freeze the stored value while the wording stays editable (§63); custom fields on 45 registers,
  with the required-field trap refused rather than allowed to invalidate a year of records, and
  every rule recompiled server-side from the stored definition (§84).
- **Part 3 — the form builder and the sheets filled in on it** (`5cb7aa2`): definitions are
  configuration, submissions are records, and they have separate registers, policies and
  permissions. A submission binds to the edition published when it was started, so March's answers
  render against March's questions. Save, submit and sign are three acts and two permissions.
  Signatures reuse the document module's table and repeat the §28 scope statement in full.
- **Part 4 — form → workflow binding** (`25483f3`): bound to the form, not the sheet, so nobody
  nominates their own approver. Five approval settings that had never been wired are reconciled —
  including auto-approve-on-timeout, deleted rather than implemented, because honouring it would
  write an approval nobody gave (§59).
- **Part 5 — expiry watches and KPIs** (`3fdaa72`): one expiry engine replacing two hard-coded
  sweeps, still behind the single cron entry (§1). A KPI holds no number of its own: every figure is
  counted through the visibility of whoever asked, and nothing is memoised, because a cached total
  belongs to whoever it was counted for.
- **Part 6 — the report builder and saved views** (`4b69e82`): a report is a saved question, not a
  saved answer. Registers, columns, filterable fields and groupings all come off a whitelist; rows
  come back through `visibleTo()`, so a shared report carries the question and never the reach.
  Saved views (§54) are per person and shareable within a scope, and applying one is a redirect into
  the ordinary run screen rather than a second path to the rows.

**Two decisions in this phase were to build nothing, and both are load-bearing:**

- **There is no escalation editor.** The Configuration Center's "Escalation rules" tile lands on the
  workflow group of the settings page, because what happens when an approval sits too long is a
  setting plus the per-stage deadlines. A third place to configure it would be the beginning of a
  second approval path (§78).
- **There is no cached KPI or report total anywhere.** `kpi_snapshots` is specified in the ERD and
  deliberately not built yet; see Phase 8.

**One class of defect found by reading rather than by failing tests**, in each case with no symptom
until it mattered: a KPI definition reached by key rather than by listing would count another
company's records under this company's letterhead (§75, fixed in part 5); a saved view whose fields
the register had stopped offering would silently run unfiltered, showing somebody the whole company
under a heading that said "Civil only" (fixed in part 6); and three Blade templates compiled to
syntax errors that `view:cache` accepted happily, one of which had been printing a stray
`the promised date@endif.` to users for several commits. `ViewsCompileTest` now sweeps all 473
templates for all three shapes in six seconds.

**Phase totals at close:** 973 tests, 6,573 assertions, green.

## PHASE 8 — Executive intelligence ✅

Executive command center · department dashboards · project command center · drill-down navigation ·
daily executive report (scheduled, per-recipient visibility) · KPI reports · exports · global
search.

**Everything carried in from Phase 7 was built.** `phpoffice/phpspreadsheet` is installed and
`.xlsx` is a real format with typed cells, a frozen header and an autofilter; DOCX is wired to the
same renderer path; exports over a row threshold are queued and delivered as a signed, expiring
link; `kpi_snapshots` exists, and the question it raised — *whose visibility was that number counted
through?* — is answered in part 1 and enforced everywhere a stored figure is served.

- **Part 1 — stored figures and the rule that makes storing them safe** (`9933f5a`): `kpi_snapshots`
  plus `KpiEvaluator` and the nightly `kpi:snapshot` sweep. A snapshot records the scope it was
  counted over, and `ReachInspector` decides per reader whether that scope is contained in their
  reach. Everybody else falls back to a live count through their own visibility, carrying a sentence
  that says the figure is theirs rather than the company's — so the fast path exists exactly where
  it is safe and the slow path is correct everywhere.
- **Part 2 — the three boards, and who each one lets in** (`a1d3371`): executive, department,
  project and personal boards composed by `WidgetComposer` from one arrangement per company. Opening
  a board and being able to read what is on it are two separate questions; a tile whose register the
  reader cannot open is never built, so it cannot appear in the page source, in a print view or over
  somebody's shoulder (§6). Which department a board is about is never settled by the URL.
- **Part 3 — the step underneath a figure, and who it lets through** (`06b726c`): the §89 drill-down.
  A tile links to the records behind it, the scope travels in the URL as context rather than
  authority, and the id is re-proven against the reader's own visibility on arrival.
- **Part 4 — one box, and the registers it is not allowed to mention** (`547affb`): global search
  across every register, each result re-checked through `visibleTo()` so the result set is exactly
  what the index pages would have shown. A search box that confirms the existence of a record by
  finding nothing slower is an oracle (§61).
- **Part 5 — two more shapes for a report, and the size at which it stops being handed over**
  (`819aa30`): `.xlsx` and `.docx` renderers, the queued export path, `exports` rows with signed
  short-lived links, and `exports:sweep`. The file goes at retention and the row stays, because what
  left the platform and who took it outlives anybody's need for the file (§59, §67).
- **Part 6 — the report that arrives unasked, and why no two copies of it match**: the daily
  executive report (§55). It is the executive board printed, composed per recipient, delivered as an
  export row rather than as a document — see docs/11 §6 for both decisions and the reasoning behind
  each.

**Three decisions in this phase were to build something narrower than the plan, and each is
load-bearing:**

- **The daily report has no bespoke sections.** docs/11 §6 named eight; building them would have
  counted, in a second place, what the KPI engine already counts, and would have made the report the
  one thing on this platform a company cannot change without a developer (§73, §78, §90).
- **Briefings are not archived as documents**, although docs/11 §6 said they should be. A document
  register that organization-wide `document.view` can read is the wrong home for eighteen
  personalised copies of one morning's figures; archiving them there would defeat §55 in the name of
  §55. The `exports` row already means "one person's file", and the row outlives the file.
- **The daily cron is hourly.** §1 allows one entry, so the entry fires every hour and each company's
  configured hour is compared in that company's own timezone. A daily cron would have given every
  tenant on the host the same send time and made `report.daily_executive_hour` a control that does
  nothing.

**Two defects found by writing a test that round-trips real output rather than asserting on
intent.** `ExcelRenderer::parse()` brought down the entire `.xlsx` export of any report with a
populated date column, because Carbon's `createFromFormat` *throws* where native PHP returns `false`
and the first of two formats tried escaped instead of falling through (found in part 5 by loading
the workbook back through PhpSpreadsheet's own reader). And three call sites — the daily report's
recipient list, `EscalationService::holdersOf()` and `NotifyWorkflowStalled` — looked roles up by
`permissions.name`, a column that does not exist; inside the correlated subquery the unqualified
`name` resolved to `roles.name`, compared a role's title against a permission string, and matched
nobody without ever erroring. That is §25 escalation and the stalled-workflow notice silently
reaching zero people since Phase 7. All three are now qualified as `permissions.key`.

**Exit criteria: met.** The §89 drill-down path works end to end (`DrilldownTest`,
`ProjectCommandCenterTest`) and search returns exactly the index-page result set (`GlobalSearchTest`).

**Phase totals at close: 1109 tests, 7081 assertions, green** — the full suite, no paths named, in
42m36s. Phase 8 added 85 tests to the 1024 standing at its start.

## PHASE 9 — Hardening ⬜

Security review · full authorization test matrix (IDOR, scope escape, notification leakage, search
leakage, file access) · N+1 and query-count budgets · load sanity check · error handling and
friendly error pages · backup and restore drill · deployment runbook · demo seed data · user
documentation.

**What this phase is actually for.** Every previous phase proved its own surface. Nothing so far has
tried to prove that a *reader* cannot get somewhere, systematically, across all of them at once —
and the Phase 8 close showed exactly why that gap matters. Three call sites asked for a permission
holder by a column that does not exist, matched nobody, and passed every suite in the codebase for
two phases, because no test ever asserted that the query returned somebody. A test that only
exercises the happy path cannot distinguish "correctly denied" from "silently broken", and this
phase is the one that has to.

- **Part 1 — the authorization matrix.** One test per register, driven from a table rather than
  written out by hand: for each of {owner, same department, other department, other organization,
  no role}, every one of {index, show, create, update, delete, export, download, drill-down} answers
  200 or 403/404, and the expected answer is written down before the test runs. §84 and §94: URL ids
  are re-proven, hidden fields are ignored, client-supplied status values are refused, and Org A
  never reaches Org B (§75). **Every case must assert a positive somewhere** — a matrix whose rows
  are all denials is a matrix that would stay green if the whole application stopped working.
- **Part 2 — the silences.** The failure shape Phase 8 found, generalised. A permission holder
  lookup that returns nobody, a notification whose audience resolves to zero, a search that finds
  nothing, an escalation that escalates to no one: each of these is *indistinguishable from working*
  unless something asserts the non-empty case. Regression tests owed specifically for
  `EscalationService::holdersOf()`, `NotifyWorkflowStalled`, `ExecutiveBriefing::recipients()` and
  every `Audience` resolution — plus keeping the static detector written during Phase 8 as a
  repository check, since `whereHas` closures silently resolving against the outer table is a defect
  class, not one bug.
- **Part 3 — query budgets.** §89. A ceiling per page, asserted, not eyeballed: the executive board,
  the project command center, every index with a status filter, and the drill-down path. The daily
  report gets its own budget because it composes a board per recipient on a cron-timed minute.
- **Part 4 — the two spanning walkthroughs, carried in from Phase 7.** Proven surface by surface but
  never as one continuous act. §73: an empty tenant to a routed, numbered, reportable register
  entirely through the screens, by somebody with no database access. §74: reorganizing a department
  tree and re-checking every reach that depended on it afterwards — the test is what happens to
  dashboards, saved filters and in-flight approvals when a department moves under a different
  division mid-approval.
- **Part 5 — deployment truth.** The Phase 8 pre-deploy read of `docs/12` found a documented
  `ProductionSeeder` that does not exist, a second queue cron that would have doubled the workers,
  a `PRIVATE_DISK` setting read by nothing, and a backup engine described in the present tense that
  was never built. Those are corrected in the doc; this part owes the rest. A real first-run path
  for a production install; a decision on whether a backup **record** belongs in the application at
  all (a platform that claims to be watching a backup it does not run is worse than one that says
  nothing); friendly 403/404/419/500/503 pages that do not leak whether a record exists; and the
  restore drill actually performed against a scratch database, with the RTO measured rather than
  estimated.
- **Part 6 — demo seed data and user documentation.** `DemoSeeder` exists and is good; what it lacks
  is a safe gate. It is currently skipped in production unless `--force` is passed — but `--force` is
  the flag an operator **must** pass to seed anything in production at all, so the guard inverts
  under exactly the conditions it exists for. That is a fix, not a feature. Then the walkthrough
  documentation a non-developer administrator actually needs, which is §73's other half.

**Exit criteria:** the authorization matrix is green and contains positive cases; no query budget is
exceeded; both spanning walkthroughs pass end to end; a restore drill is documented with a measured
RTO; and a production install can be completed from `docs/12` alone, by somebody who has not read
this repository.

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
