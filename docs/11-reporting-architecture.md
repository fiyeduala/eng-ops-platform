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

## 2. Filters (§54)

Universal filter set: date range · organization unit (subtree) · project · employee · status ·
category · discipline · vendor/client · location · custom fields marked `is_filterable`.

Filters are declarative and shared between screen and export, so an export always reflects exactly
what the user was looking at. Saved filters (`saved_filters`) are per user and shareable within a
scope.

## 3. Export (§54, §66)

| Format | Engine | Notes |
|--------|--------|-------|
| PDF | Dompdf | branded header/footer, logo, generated-by, timestamp, page x of y |
| Excel | PhpSpreadsheet | typed cells, frozen header, autofilter |
| CSV | streamed | large exports stream, never buffered |
| Print | CSS `@media print` | no server round-trip |
| DOCX | PhpWord | editable deliverables and minutes |

Exports over a row threshold are queued and delivered as a notification with a signed, expiring
download link — this keeps cPanel PHP timeouts irrelevant.

Every export writes an audit entry recording the filter set and row count (§59).

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

Scheduler job at a configurable time per organization. Sections: **People** (attendance, absence,
leave) · **Projects** (progress, delays, milestones, risks, issues) · **Engineering**
(deliverables, reviews, pending approvals) · **HSE** (incidents, inspections, corrective actions) ·
**Procurement** (requests, POs) · **Finance** (income, expenses, payments) · **Inventory** (stock
movement, low stock) · **Fleet** (usage, maintenance).

Rendered as a branded PDF and emailed to configured recipients. **Each recipient's copy is
generated under their own visibility** — a Finance Manager and an HSE Manager receive different
documents from the same schedule. Reports are archived as documents so they remain auditable.

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
