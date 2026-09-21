# 11 — Reporting, KPI & Dashboard Architecture

## 1. One source of truth (§54)

Reports are **projections of the operational tables**, never a parallel data store. If a report and
a list page disagree, that is a bug. Every report query passes through the same `visibleTo()`
filter as the corresponding index, so two users legitimately see different numbers — and neither
sees anything they could not open directly (§61).

```
Operational tables
   → ReportQuery (filters + visibility)      ← same compiler as index pages
      → ReportDefinition (columns, groupings, aggregates)
         → Renderer: HTML · PDF · Excel · CSV · Print
```

**As built (Phase 7 part 6).** `ReportCatalogue` is the whitelist: which registers can be reported
on, and per register which columns may be printed, which fields may be filtered, and which may be
grouped or sorted. Nothing a user types names a table, a column or an operator directly, and the
whitelist is re-checked on the way *out* as well as in, because a definition outlives the register
it names (§84). `App\Engines\Query\FilterCompiler` is the one compiler, used by both `ReportRunner`
and `KpiEvaluator` — §1 above asks a report and the KPI beside it to narrow a register identically,
and two implementations that agree today are not that guarantee. Rows come back through
`visibleTo()` with the module's own view permission, which is what makes a report safe to share: it
carries the question and never the reach.

## 2. Filters (§54)

Universal filter set: date range · organization unit (subtree) · project · employee · status ·
category · discipline · vendor/client · location · custom fields marked `is_filterable`.

Filters are declarative and shared between screen and export, so an export always reflects exactly
what the user was looking at. Saved filters (`saved_filters`) are per user and shareable within a
scope.

**As built.** A saved view stores conditions and nothing that came back — no rows, no ids, no
counts. Sharing puts the *name* on other people's screens; each of them runs it against their own
visibility, so a director and a site clerk applying one shared view see different rows and both are
right. Saving rides on `report.view` and there is deliberately no `saved_filter` permission:
keeping your own shortlist is not an authority a company should have to grant. Sharing with the
whole company does require `report.create`, because that is publishing.

Three properties are load-bearing and each is asserted by a test:

- **Every route hangs off a report.** The register a view asks about is read from the report the
  person is standing on, never posted, so there is no id anyone can supply to attach conditions to a
  register they were not looking at (§74, §84). A view and a report belonging to different registers
  is refused as not found, which is the shape an IDOR attempt takes when each id passes its own
  check (§94).
- **Applying a view is a redirect, not a render.** It redirects into the ordinary run screen with
  the conditions in the query string, where they meet the same whitelist, the same compiler and the
  same `visibleTo()` as conditions typed by hand. A saved view is therefore incapable of reaching a
  row an ad-hoc filter could not, because downstream of the redirect there is no difference between
  the two.
- **A view that no longer narrows anything is refused, not run wide.** Conditions naming fields the
  register has stopped offering are dropped on the way out; if that leaves nothing, applying it
  would run the report unfiltered and show somebody the whole company under a heading that says
  otherwise, with a row count they would go on to quote. The view is marked "No longer works" in the
  list rather than hidden, so it can be found and re-saved.

## 3. Export (§54, §66)

| Format | Engine | Status | Notes |
|--------|--------|--------|-------|
| PDF | Dompdf | **built** (Phase 7 pt 6) | branded header/footer, logo, generated-by, timestamp, page x of y |
| CSV | streamed | **built** (Phase 7 pt 6) | large exports stream, never buffered |
| Print | CSS `@media print` | **built** | no server round-trip |
| DOCX | PhpWord | **built** (Phase 8 pt 5) | landscape, provenance in the page footer, group labels as headings |
| Excel | PhpSpreadsheet | **built** (Phase 8 pt 5) | typed cells, frozen header, autofilter |

All four are offered on every report. They are four different jobs rather than one job with four
buttons: CSV is what every other system will accept and the one Excel will quietly corrupt — it
carries no types, so `03-0450` opens as a date and a document number is destroyed; XLSX carries the
types, so a cost column sums and a reference survives; PDF is the copy meant not to be edited; DOCX
is the copy meant to be, the draft somebody writes their commentary into before it goes upstairs.

**Typing a cell is a decision, not a conversion.** `ReportResult` rows are already rendered strings
by the time the workbook is built, so the Excel renderer never re-derives a value. A cell is written
as a number or a date only when the register's own schema says that column is one *and* the printed
value round-trips exactly; a relation column is always text, because the cell holds a name while the
column behind it holds an id. Everything else is written with an explicit string type, which is also
what makes a cell beginning `=` inert rather than a formula (§84).

Exports over a per-company row threshold are prepared in the background and delivered as a
notification with a signed, expiring download link. **Built** (Phase 8 pt 5).

- `report.export_queue_threshold` (default 500, clamped to `ReportDefinition::MAX_ROWS`) is the
  decision, and it is about the host rather than the data — cPanel's 30-second
  `max_execution_time` is not ours to raise, and the same company on a VPS has no such limit.
- The rows are counted *before* any are built. `ReportRunner::countUpTo()` asks the same query for at
  most threshold-plus-one ids; running the report to find out whether running the report will time
  out would be the timeout with extra steps.
- The queue runs from the one cron entry (§1): `queue:work --stop-when-empty --max-time=55` every
  minute, no Supervisor and no daemon. A company that never installs the cron sees large exports sit
  at "Waiting" on a page that says so — a missing feature, not a broken system.
- The background run re-runs the report **as the requester, inside their organization**, and never
  reuses a result computed in the web request. A serialized `ReportResult` would be a second way to
  get visibility right, and therefore a second way to get it wrong.

**A prepared export belongs to the person who asked for it, not to the company.** `ExportPolicy`
reads ownership and no permission at all: not an administrator, not the report's author, not somebody
holding `report.view_all`. The file is one person's visibility frozen at one moment, and handing it
to a wider reader makes the platform lie about a number in one direction while withholding it makes
it lie in the other — so it is given to nobody but its owner (§67, §61). `report.export` governs
whether a file may be *made*; ownership governs who may *collect* it. The grant creates, ownership
collects.

Four gates gate the download, and all four are load-bearing: `auth` (a signed link is not a session,
so a forwarded URL lands on the sign-in page), `signed` (the link expires on its own, so an old
notification is not a permanent key), `ExportPolicy` (the requester, including against an
administrator), and the retention date read from the row rather than the signature. The first and
third protect the file; the second and fourth stop it being protected forever.

**Retention is a control, not housekeeping.** `report.export_retention_hours` (default 12) is how
long the file stays on disk; `exports:sweep` runs hourly — hourly rather than daily because the
setting is measured in hours — deletes the file and marks the row expired. The row outlives the file
deliberately: what left the platform, who took it, with which filters and how many rows is an audit
question that outlasts anybody's need for the file (§59). A prepared export is the one copy of
company data on disk that nobody is managing — no category, no owner, no review date — and readable
long after the access that produced it was withdrawn. Retention is the only thing that stops it
being a permanent bypass of the permission that created it.

Every export writes an audit entry recording the filter set and row count (§59). **Built**, and
every export re-runs the report rather than exporting a cached answer, so an export obeys the
exporter's own visibility at the moment they asked for it — not the visibility of whoever last
opened the screen. For a queued export the entry is written when the file is produced rather than
when it is requested, because only then is the row count known — and the row count is the part of
§59 that makes an old export reconcilable.

## 4. Dashboards (§52, §53)

Dashboards are compositions of **widgets**. A widget declares its data source, required permission
and refresh policy; the dashboard renders only widgets the viewer may see, so one dashboard
definition serves every role safely.

### Executive Command Center (§52)

Active projects · project health (RAG) · progress · delayed projects · pending approvals ·
financial position · procurement pipeline · HSE performance · QA/QC status · attendance summary ·
HR alerts · inventory (low stock) · fleet status · assets · upcoming meetings · critical issues ·
open risks · overdue actions.

Every tile is a **drill-down entry point** (§89):

```
Projects · Project Alpha 72%
   → Engineering 80% · HSE 91% · Procurement 64% · Finance 78%
      → Engineering: deliverables · tasks · documents · RFIs
         → Document · Revision B · Pending Approval
            → Workflow · Awaiting Engineering Manager
```

Context is carried in the URL and re-authorized at each level.

### Department dashboards (§53)

| Department | Widgets |
|------------|---------|
| Engineering | deliverables, drawings, RFIs, pending reviews, tasks, projects |
| HSE | incidents, near misses, inspections, risks, open corrective actions, training compliance |
| Procurement | requests, RFQs, quotations, POs, cycle time |
| HR | headcount, attendance, leave, recruitment pipeline, training expiry |
| Finance | expenses, income, budget variance, payments due |
| Project Mgmt | progress, schedule, tasks, risks, issues, budget, documents |

## 5. KPI engine (§56)

```
kpi_definitions  name, module, scope(organization|department|project|employee),
                 aggregate(count|sum|avg|ratio|percent), source json, filters json,
                 target, unit, direction(higher_better|lower_better), period, thresholds json
kpi_snapshots    kpi_definition_id, period_key, scope_type, scope_id, value, computed_at
```

`source` is a **whitelisted** entity + column descriptor, never raw SQL — administrators compose
KPIs from safe building blocks, so a KPI can never become an injection vector.

Seeded KPIs: schedule performance index · completion rate · budget variance · deliverables
completed · overdue deliverables · revision rate · incident frequency · near-miss ratio · open
corrective actions · procurement cycle time · attendance rate · training compliance.

Snapshots are computed by the scheduler so trend charts are cheap and historical values remain
correct even after underlying records change.

## 6. Daily Executive Report (§55)

**Built in Phase 8, part 6.** `ExecutiveBriefing` · `report:daily-executive` ·
`resources/views/reports/daily-executive.blade.php`.

### The report is the executive board, printed

The sections are **not** a fixed list in code. `ExecutiveBriefing::briefingFor()` calls
`WidgetComposer::compose('executive', $recipient, null, $asOf)` — so what a company receives every
morning is exactly what that company arranged on its executive board, through the screens, with no
code (§73). A section is added by adding a tile.

This replaces the earlier plan of eight hand-written sections (People · Projects · Engineering ·
HSE · Procurement · Finance · Inventory · Fleet). Those remain the *expected* content, but as tiles
a company places rather than queries a developer writes. Eight bespoke queries would have counted
the same things the KPI engine already counts, in a second place, drifting from it within a release
— §78's argument about there being one workflow engine, applied to figures.

### Each copy is one person's

The requirement §55 actually turns on, and why the report cannot be rendered once and mailed to a
list. `WidgetComposer` re-checks the board permission with no middleware in front of it, counts
every figure through that reader's own reach, and refuses a stored snapshot to anybody whose reach
does not contain the scope it was counted over. A Finance Manager and an HSE Manager receive
different documents from one schedule, correctly, and neither is shown a number derived from rows
they could not open.

The PDF says so on its own face, above the figures, and every branch of the template that prints a
value also prints the tile's footnote. A PDF is forwarded; a figure that arrives without its
footnote becomes *the company's* number in somebody else's inbox.

Nobody with an empty board is sent anything. A blank PDF at seven every morning is how a scheduled
report teaches its recipients to filter it.

### The recipient list

Everybody in the tenant holding `dashboard.view_executive`, on an active role, with an active
account — read off the permission rather than a role name, because §5 makes the names the company's
to choose. `ExecutiveBriefing::MAX_RECIPIENTS` (40) is a ceiling on what one cron minute may cost:
above it the run sends **nothing** and warns, rather than truncating at an alphabetical cut-off
nobody can see.

### Archived as an export row, not as a document

The one instruction in the original plan that is deliberately **not** followed. A document lives in
a register that organization-wide `document.view` can read, so archiving eighteen personalised
copies as documents would publish to the whole company exactly the per-recipient figures the
paragraph above exists to keep apart. It would defeat §55 in the name of §55.

The `exports` table already means the right thing: one person's file, on the private disk, behind
an ownership policy (`ExportPolicy` consults no permission — an administrator gets a 403 on a
colleague's briefing), reachable only through a signed short-lived link. And **the row outlives the
file**, which is the audit. After retention the row stays as `expired`, a permanent record that
this person was sent this briefing on this date; the figures themselves are reproducible from a
board that is configuration.

Briefing rows carry `entity_type = 'executive_briefing'` and no `report_definition_id`, which is
what `Export::isBriefing()` reads — not "has no report definition", because a report deleted after
its export was prepared also leaves that column null and those two rows mean opposite things. The
exports page gives them their own vocabulary ("Sent to you automatically · N sections"), and
`dashboard.view_executive` opens the **My exports** menu item so a director who never exported
anything still has a way back to yesterday's copy.

### Hourly cron, per-company hour

§1 allows one cron entry. `report:daily-executive` is scheduled **hourly**; it converts the moment
to each company's `general.timezone` and compares against that company's
`report.daily_executive_hour`. A daily cron would have given every tenant on the host the same send
time and made the setting a control that does nothing, which is worse than not offering it.

Idempotent per recipient per day, like §7's engines: each briefing is keyed by
`entity_type` + `requested_by` + the day-stamped name, so a cPanel cron catching up by running
twice, a replayed migration, or an administrator running it by hand sends nothing a second time.
`--force` replaces the day's copy and ignores the hour.

One tenant's failure is logged (organization id, exception class, message — never contents, §68)
and counted; the loop continues, and the command still exits `SUCCESS`, because a non-zero exit
from an hourly cron mails the host administrator about somebody else's misconfigured board every
hour until it is fixed.

### Settings

| key | default | meaning |
| --- | --- | --- |
| `report.daily_executive_enabled` | `true` | whether this company is sent one at all |
| `report.daily_executive_hour` | `7` | the hour, in the company's own timezone |
| `report.daily_executive_retention_days` | `14` | how long each copy is kept |

Retention is in days rather than the prepared export's hours because this file arrives unasked and
is read the next morning — but it is still one person's copy of their own figures, so it is not
kept forever. `exports:sweep` deletes the file and leaves the record.

### Notification

`report.daily_executive` — switchable, unlike `export.ready` and `export.failed`. A scheduled
report that cannot be turned off individually gets turned off by a mail rule, which is worse for
everybody. The notice carries the date and a signed link valid for exactly as long as the file
exists, and **no figures** (§68): no two copies hold the same numbers, so a notice quoting one
would be wrong for everybody it was forwarded to.

## 7. Escalation & Expiry engines (§57, §58)

Both are scheduler-driven, configuration-only, and share the notification dispatcher.

```
escalation_rules  entity_type, condition json, after_days, notify_type, notify_ref,
                  sequence, is_active
expiry_watches    watchable_type/id, field, thresholds json (e.g. [90,60,30,14,7,1]),
                  responsible_type/id, last_notified_threshold
```

Expiry covers certifications · training · insurance · permits · contracts · vehicle documents ·
vendor documents · employee documents · equipment certifications · ACL grants. Registration is
automatic via a `HasExpiry` trait on any model with an expiry date column, so new expiring entities
are monitored without touching the engine.

Both engines are **idempotent** — a re-run on the same day sends nothing twice.

## 8. Performance (§95)

Pagination everywhere (no unbounded `get()`) · eager loading with explicit `with()` (an N+1 detector
fails the test suite) · covering indexes for dashboard aggregates · KPI snapshots instead of live
recomputation · cached counters for badge numbers, invalidated on write · heavy exports and report
generation queued · `chunkById()` for scheduler sweeps.
