# 16 — Development Handoff

Review started: 14 September 2026. Handoff finalized: 16 September 2026.
Observed Git HEAD: `6bcb0c3`.

This handoff records where development has reached and what to verify before continuing. The
review covered the README, all fifteen existing architecture and roadmap documents, recent commits,
the core engines and their integration patterns, and the current uncommitted assets and fleet
work. It is an orientation and checkpoint review, not a claim that every source line has been
audited. The working tree contains unfinished work that must be preserved.

## Product and implementation constraints

EngOps is a configurable engineering and operations platform for organizations working across
departments and projects. It uses Laravel, PHP, MySQL/MariaDB, Blade, Tailwind and Alpine, with
native browser document authoring and document import/export.

The five shared engines remain the foundation: organization and access; workflow; records and
documents; activity and audit; and configuration. New modules consume these engines rather than
creating their own approvals, authorization, audit or status implementations. See
[system architecture](01-system-architecture.md) and [module dependencies](02-module-dependencies.md).

Tenant isolation is mandatory. Lists, individual record actions, relation selections, downloads,
exports and notifications must respect the same organization, permission, scope, record ACL and
confidentiality rules. Roles and company-specific values are configurable data. Historical
approvals, signatures, custody changes and stock movements must remain traceable.

V1 targets Laravel-compatible cPanel hosting. Database queues and caches, cron scheduling and
private file storage are supported without requiring Redis, WebSockets, Docker, Supervisor or a
Node runtime on the production server. Node is used to build browser assets. See
[cPanel deployment](12-cpanel-deployment.md) and [VPS migration](13-vps-migration.md).

## Current checkpoint

| Area | Observed state |
|---|---|
| Phases 1–4 | Recorded complete in the roadmap: foundation, core operations, documents/workflow, engineering/HSE/quality |
| Phase 5, parts 1–3 | Committed commercial, procurement and inventory work: vendors, clients, contracts, purchase requests, RFQs, quotations, orders, receipts, warehouses, stock ledger and material requests |
| Phase 5, part 4 | Committed in `ad0a976`: assets, custody transfers, maintenance, vehicles, fuel logs, vehicle documents, project-cost posting, cost centers and controlled budgets |
| Phase 5, part 5 | Operational finance: tax codes, expenses, income, payments and petty cash — see [below](#phase-5-part-5--operational-finance--17-september-2026) |
| Phase 5 exit criterion | Met: purchase request → approval → purchase order → goods receipt → stock transaction → project cost. **Phase 5 is complete.** |

At the start of this review, the [README status](../README.md#status) and
[roadmap](14-roadmap.md) still described Phase 5 as unstarted. The review reconciles that stale
checkpoint with the committed work. Commercial, procurement and inventory already exist; their
verification and remaining integration are the continuation point.

## Phase 5 part 5 — operational finance — 17 September 2026

One additive migration (`2026_09_18_000001_create_finance_registers`) creates `tax_codes`,
`expenses`, `incomes`, `payments`, `petty_cash_accounts` and `petty_cash_transactions`. Each
register follows the full slice: model, policy, service, form request, controller, routes, views,
events/notifications, tests and demo data.

Decisions worth knowing before changing this code:

- **Server-side money (§84).** Tax, totals, currency, status, payee and the paid/received caches are
  protected attributes. Rates and recoverability are copied onto each record when saved, so a later
  change to a tax code never rewrites history. Recoverable tax is excluded from project cost.
- **Duty separation is a setting, not a hard rule.** `finance.separate_claimant_and_approver` and
  `finance.separate_payment_approver_and_payer` default on. When a company waives one, the action
  succeeds and the activity entry records `self_approved` / `self_released`.
- **No over-commitment.** Outstanding balance subtracts draft, submitted, approved and paid
  payments, checked under a row lock on the payable at create, update, submit, approve and pay.
- **Payables are resolved server-side.** The form sends `type:id`; only `purchase_order`,
  `expense` and `income` morphs resolve, only within the tenant, and the user must be able to
  view the payable (403 otherwise, and it is not offered in the picker).
- **Petty cash is a ledger.** Vouchers are append-only (update/delete throw); the balance is a
  cache verified by `balanceMatchesLedger()`. Corrections are one reversal voucher per original,
  which also reverses the posted project cost. A top-up whose cash is already spent cannot be
  reversed below zero.
- **Access fixes found while testing.** `Expense::accessOwnerColumns()` includes `claimant_id`, so
  the baseline `own` grants reach claims entered on a claimant's behalf. The baseline gained
  `expense.edit:own` so a returned claim can be amended. Finance manager/officer templates gained
  `petty_cash` and `tax_code` grants; see the [RBAC matrix](06-rbac-matrix.md).

Demo data: Meridian has 7.5% VAT, claims in each state (paid, approved, submitted, returned), a
supplier advance paid and a balance awaiting approval against the first payable purchase order, a
part-received interim valuation against its client contract, and a site tin with vouchers and one
reversal. Kestrel has 20% VAT, a fully received maintenance invoice, and a mileage claim approved by
the MD whose payment waits for release because nobody there holds `payment.pay`.

Tests live in `tests/Feature/Finance` (`ExpenseTest`, `PaymentTest`, `PettyCashTest`,
`FinanceAccessTest`; 26 new cases) with fixtures in `tests/Support/BuildsFinance.php`, covering
IDOR and cross-tenant 404s (§75, §94), foreign tax code/client/project rejection, and
permission-less refusal of every register and write.

Validation on 17 September 2026: `php vendor/phpunit/phpunit/phpunit --no-progress` **passed, 528
tests and 3,124 assertions** (22 minutes 32 seconds, in-memory SQLite). The finance folder alone:
35 tests, 244 assertions. `migrate:fresh --seed` completed on the local MySQL database; seeded tin
balances, receipts and project-cost rows reconcile with their ledgers. Pint passed on touched files.

Not in this slice: bank reconciliation, multi-currency payments against a single payable, and
accounting exports. Payments inherit their payable's currency.

## Continuation implemented — 16 September 2026

The seven findings below describe the original review. This continuation implements their repairs
in the existing working tree:

- Vehicles, maintenance jobs, fuel logs and certificates inherit parent ACL denials,
  confidentiality and tenant protection. Missing organizational/project scope columns resolve
  through the declared parent relationship. Child permissions still govern their own module:
  an assigned driver does not need `asset.view` merely to see an otherwise accessible vehicle.
  A child share cannot bypass a parent's protection. Maintenance project filtering uses the asset.
- Certificate renewals link the replacement to its predecessor. Old certificate details and scans
  remain historical evidence; warnings stop on the replacement's effective date. Filing a future
  renewal does not hide a current gap. Linked certificates cannot be deleted, and replaced ones
  cannot be edited or renewed twice.
- Consumption includes partial fills between the latest two full tanks. Missing readings suppress
  the estimate. Custody transfers and odometer changes refresh locked rows inside transactions,
  preventing stale instances from corrupting the trail or decreasing mileage.
- Maintenance completion/cancellation releases the machine only when no other started, open job
  remains. Reopening resets completion and downtime and restores maintenance availability.
  External work-order references survive create/edit.
- Accepted receipt lines containing exactly one item can become assets through the receipt page.
  Both receipt and purchase-order access are required. A unique source link prevents duplicate
  registration, including after soft deletion. Source acquisition details are authoritative;
  foreign prices use the order's recorded exchange rate with decimal arithmetic. Missing foreign
  exchange rates are refused. The receipt page links back to the resulting visible asset.
- HTTP coverage exposed and fixed an existing enum-to-string rendering error on the receipt page.

Two additive migrations record renewal links/effective dates and enforce receipt-source
uniqueness. They have been exercised through the test database, not applied to the user's local or
production database. Before applying them to an existing populated deployment, check for duplicate
`assets.goods_receipt_item_id` values. Historical renewals previously recorded only in activity
metadata are not automatically backfilled.

New coverage lives in `AssetAccessTest`, `AssetLifecycleTest`, `VehicleDocumentTest` and
`ReceiptAssetTest`. Production MySQL lock contention and a browser/mobile visual walkthrough remain
separate deployment checks; SQLite regression tests cannot establish those properties.

## Continue in this order

1. Review the migration notes above before applying the verified asset/fleet checkpoint to a
   populated deployment. Preserve the uncommitted application work when preparing a commit.
2. Verify the earlier Phase 5 modules and their integration with the current checkpoint. Preserve
   existing changes and distinguish completed behavior from planned interfaces and placeholders.
3. Continue operational finance as complete module slices, using the existing engines and
   authorization conventions. The [database design](03-database-erd.md#f-commercial-operations)
   provides the target entities; money must retain an explicit currency and exact decimal
   representation.
   The first slice now posts source-backed project costs and has reconciled stock valuation into
   the organization's currency. Preserve the original currency, fixed rate and converted amount
   when adding manual expenses, income and payments. Budgets and cost centers now consume the
   project-cost ledger for live variance without storing editable actual figures.
4. Preserve the tested Phase 5 integration chain through project-cost posting. Receipt
   acceptance posts stock and cost inside the same database transaction through
   [`GoodsReceiptService::postToStock()`](../app/Services/GoodsReceiptService.php). It carries the
   order's project and organizational unit, uses the order price and skips lines with existing
   movements. The cost leg is idempotent by receipt-line source and also covers deliveries without
   a warehouse or catalogued item. Keep those properties as the finance registers expand.
5. Reconcile the README, roadmap and this handoff with the verified state before declaring Phase 5
   complete. Follow the roadmap's complete vertical slice: migration, model/relationships,
   policy, service/action, request, controller, routes, views, notifications/events, tests and
   seed data. A dependent phase starts only after its prerequisites are verified end to end.

## Original assets and fleet findings

These are the original review findings, addressed by the continuation above. Small in-memory model
probes reproduced the fuel and expiry-warning defects; the other findings initially lacked targeted
regression tests. The existing full suite passed, but did not cover these cases.

1. **Parent access inheritance:** vehicle access does not consistently inherit the underlying
   asset's ACL, classification, unit and project scope. This can expose asset details through a
   child page or lose valid parent scope. Inspect
   [`VehiclePolicy`](../app/Policies/VehiclePolicy.php),
   [`Vehicle`](../app/Models/Vehicle.php),
   [`ScopeCompiler`](../app/Engines/Access/ScopeCompiler.php) and
   [`VehicleController`](../app/Http/Controllers/VehicleController.php). Apply the same review to
   asset maintenance and vehicle documents; test both direct URLs and list visibility.
2. **Renewal and expiry:**
   [`VehicleDocumentService`](../app/Services/VehicleDocumentService.php) retains an expired
   certificate when renewing it without linking a superseding record. The expiry sweep and
   [`Vehicle`](../app/Models/Vehicle.php) warning calculation can consequently continue reporting
   an expired document after renewal. Preserve history while making the current certificate
   explicit and testing renewal plus the sweep together.
3. **Fuel consumption:** [`Vehicle`](../app/Models/Vehicle.php) omits intermediate partial fills
   from consumption calculations. A 20-litre partial fill followed by a 30-litre full fill over
   500 km should produce 10 L/100 km; the reviewed calculation produces 6. Verify the full-to-full
   interval and all fuel added within it.
4. **Stale custody and odometer writes:** [`AssetService`](../app/Services/AssetService.php) changes
   custody and [`FleetService`](../app/Services/FleetService.php) advances the odometer without
   first refreshing and locking the relevant row. Test conflicting changes so custody history
   cannot diverge and the cached odometer cannot decrease through stale model instances.
5. **Maintenance lifecycle:** review completion and reopening when an asset has multiple
   maintenance jobs in [`AssetMaintenanceService`](../app/Services/AssetMaintenanceService.php)
   and [`AssetMaintenanceController`](../app/Http/Controllers/AssetMaintenanceController.php).
   Changing one job must account for the other outstanding jobs before changing availability.
6. **Maintenance fields and filtering:** the maintenance service strips
   `work_order_reference`, and a maintenance `?project` filter reaches shared
   [`RegisterController`](../app/Http/Controllers/RegisterController.php) logic that assumes a
   `project_id` column the maintenance table does not have. Preserve the reference through the
   intended write path and resolve project filtering through the asset relationship.
7. **Receipt-to-asset UI integration:** receipt-to-asset creation currently exists through the
   service and demo seeder, without an HTTP path. A service example is not yet a user-completable
   workflow; add and authorize the intended entry point before describing the integration as
   available through the application.

The original assets suite contained 21 tests (nine custody and twelve fleet cases). The review found
no coverage there for vehicle-document renewal, expiry sweeps or child ACL inheritance, and only
limited HTTP coverage. The shared `Phase1SmokeTest` also checks parameterless authenticated pages,
but skips record routes for which its fixtures supply no bound parameter. Add targeted regression
coverage alongside the repairs instead of treating the existing test count as evidence for those
missing cases.

## Direction after Phase 5

- **Phase 6 — People:** employee records, recruitment/onboarding, attendance, timesheets, overtime,
  leave, training, performance, personnel changes, payroll and visitors. Leave and timesheet
  approvals must use the same workflow engine as documents.
- **Phase 7 — Advanced administration:** form builder, custom fields, configurable statuses,
  numbering, workflow configuration, escalation/expiry rules, KPIs, reports and the configuration
  center. A non-developer must be able to configure a company and reorganize it through the UI.
- **Phase 8 — Executive intelligence:** dashboards, contextual drill-down, scheduled reports,
  exports and global search, all filtered by each viewer's authorization.
- **Phase 9 — Hardening:** authorization/security review, query and load checks, user-facing error
  handling, backup/restore drill with measured recovery time, deployment runbook, demo data and
  user documentation.

See the [development roadmap](14-roadmap.md) for the phase exit criteria and cross-phase rules,
including auditing every state change and verifying field-facing screens at 375px width.

## Documentation discrepancies

Some architecture documents describe the target design rather than current behavior. Use current
code, tests, recent commits and explicit implementation notes to resolve these differences before
building on them.

- The [RBAC matrix](06-rbac-matrix.md#4-action--gate-mapping) says `manage` implies
  view/create/edit/delete/assign. The [Phase 2 roadmap notes](14-roadmap.md) explicitly state that
  permission implication is absent by design and explain why missing `task.view` grants had to be
  added. Do not assume a management grant automatically provides read access.
- The [access scope table](04-access-model.md#5-access-scopes-7) includes followers under assigned
  access, while the roadmap's task tests explicitly state that following a task grants nothing.
  The same document's `max_scope` formula should not be read as collapsing independent project and
  organization-unit scope axes into one rank: applicable grants are additive within their bounds.
- The [dependency map](02-module-dependencies.md#2-cross-module-integration-points-90) originally
  described `GoodsReceived` listeners posting stock and project cost. Both are now direct service
  calls inside receipt acceptance's transaction so state, stock and costs commit or roll back
  together.
- The notification design includes planned delivery features. Its
  [implementation notes](10-notification-architecture.md#9-as-built--phase-2) explain deferred
  push transport, digest delivery and de-duplication. Do not expose configuration switches for
  features without a working producer or delivery path.
- The [Phase 4 architecture](15-hse-quality-architecture.md#10-known-limits) records workflow and
  spawn limits. Having `HasWorkflow` or an inherited submission method does not by itself prove a
  register has an HTTP submission route; inspect the actual routes and caller before claiming a
  configured workflow is reachable.

## Validation before the continuation

- `php vendor/phpunit/phpunit/phpunit --no-progress`: **passed**, 465 tests and 2,649 assertions;
  runtime 47 minutes 19 seconds, peak memory 150 MB. Tests use the configured in-memory SQLite
  database. This does not verify production MySQL/MariaDB behavior or the uncovered findings above.
- `npm run build`: **passed**, 117 modules transformed. The initial sandbox attempt failed with
  an esbuild subprocess `EPERM`; the permitted run outside that restriction succeeded. Vite still
  warns about the approximately 566 kB JavaScript chunk, consistent with the existing editor
  code-splitting item in the roadmap.
- Two temporary, database-free model probes reproduced the fuel and expiry-warning defects.
  A full tank at 1,000 km, 20-litre partial fill at 1,200 km, and 30-litre full fill at 1,500 km
  returned **6 L/100 km instead of 10**. With the date frozen at 14 September 2026, an expired
  insurance certificate plus its current same-type replacement still made
  `Vehicle::hasLapsedDocuments()` return **true**. The temporary probe was removed afterward.
- `git diff --check`: passed during the review; recheck after subsequent edits.
- GitHub access was verified as `fiyeduala`: repository permissions include push, SSH authentication
  succeeds, and remote `main` matched local `6bcb0c3`. No commit or push was made during this review.

The initial review changed documentation only. The subsequent continuation changes application
code and tests as described above, preserving the existing implementation. Project-cost posting is
now implemented; Phase 5 remains open for the other operational-finance registers.

## Continuation validation

- Operational-finance and receipt integration: **19 tests, 85 assertions passed**, including exact
  exchange conversion, direct-to-site cost posting, acceptance rollback and foreign stock value.
- Route/access smoke and tenant-scope coverage: **53 tests, 761 assertions passed**.
- Budget and cost-center coverage: **4 tests passed**, covering exact allocation totals, ledger
  variance, lifecycle freezing, duplicate-category defence, tenant binding and authorization.
- Shared route smoke after adding the budget pages: **5 tests, 279 assertions passed**.
- `php vendor/phpunit/phpunit/phpunit --no-progress`: **498 tests, 2,853 assertions passed**, 27
  minutes 19 seconds, peak memory 156 MB.
- Receipt registration regressions: **5 tests, 30 assertions passed**.
- Renewal/download regressions after the final classification adjustment: **5 tests, 33 assertions
  passed**.
- `npm run build`: **passed**, 117 modules. The existing approximately 566 kB JavaScript chunk
  warning remains.
- Both new migrations passed an isolated SQLite up/down/up check while preserving existing rows.
  The temporary check script was removed afterward.
- Pint checks passed for the PHP files touched by this continuation. `git diff --check` passed.
- `php vendor/phpunit/phpunit/phpunit --log-junit storage/logs/continuation-suite.xml`:
  **492 tests, 2,816 assertions passed**, 29 minutes 51 seconds, peak memory 154 MB. The suite uses
  in-memory SQLite. The final renewal classification adjustment was also covered by the focused
  renewal run reported above.
- No commit or push has been made.
