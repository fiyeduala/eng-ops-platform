# 15 — HSE & Quality Architecture (Phase 4)

Companion to docs/07 (Workflow, Engine 2) and docs/09 (Documents, Engine 3). This document covers
the sixteen registers built in Phase 4 — five engineering, nine HSE, two quality — and the two
things that make them one system rather than sixteen CRUD screens: **the register base class** and
**the spawn contract**.

---

## 1. What these registers are for

Every one of them exists to answer a question that will be asked long after the person who wrote the
row has left:

| Register | The question it answers |
|---|---|
| `deliverables` | What did we promise to produce, when, and where is it now? |
| `drawings` | Which revision of this drawing is current, and who approved it? |
| `rfis` | What did we ask, when, and what did they say? |
| `submittals` | What did we send for review, and what code came back? |
| `change_requests` | Who asked for this change, what did it cost, and who agreed? |
| `incidents` | What happened, how bad was it, and how bad could it have been? |
| `investigations` | Why did it happen — the second why, not the first? |
| `corrective_actions` | What are we doing about it, who owns it, and did it work? |
| `hse_observations` | What did somebody notice before it became an incident? |
| `risk_assessments` | What did we say the risks were before we started? |
| `hse_inspections` | What did the site actually look like on the day? |
| `toolbox_talks` | Who was told, and can they be named? |
| `permits` | Who authorised this work, and on what conditions? |
| `training_records` | Is this person's ticket still valid today? |
| `quality_inspections` | Did the work meet the criterion it was measured against? |
| `ncrs` | What failed, what was decided about it, and did the fix hold? |

Nothing in this list is a document. Documents are Engine 3, and these registers reference them
(`toolbox_talks.document_id`, `training_records.document_id`, drawings carry a document) rather than
duplicating them.

---

## 2. The register base class

`App\Services\RegisterService` is the create/update half of every register, written once. Twelve
registers each writing their own `create()` is twelve chances to forget one of five things, and each
omission is a different silent bug:

- forget `organization_id` → the row belongs to nobody, and the global scope hides it from everybody
  including the person who just created it (§75);
- forget the number → the register has a blank column its users identify records by;
- forget the initial status → every status filter skips the row;
- forget the classification → the row is right and the model in memory is not, which is a real
  defect and is described in §7 below;
- forget to strip `organization_id` out of the submitted attributes → a posted form field moves a
  record into another tenant (§84).

```php
$record = new $class($this->safeAttributes($attributes));

$record->setAttribute('organization_id', $actor->organization_id);
$record->setAttribute('created_by',      $actor->getKey());
$record->setAttribute('number',          $this->resolveNumber($attributes));
$record->setAttribute('status_id',       $this->statuses->initialFor($this->entityType())?->id);

$this->stampConfidentiality($record);
$this->beforeCreate($record, $attributes, $actor);
$record->save();
```

`safeAttributes()` is a **deny list applied in the service**, not a `$fillable` on the model. Models
are filled from a dozen places — seeders, factories, imports, the workflow engine — and only some of
them are HTTP. The service is the one place every user-originated write passes through. Each
subclass adds its own protected columns: `Submittal` protects `review_code`, `reviewed_by`,
`reviewed_at`, `resubmittal_of_id`; `Ncr` protects the disposition columns; and so on. A posted
`review_code` is therefore ignored silently rather than rejected loudly, because rejecting it tells
the person probing exactly which field they found.

**Entity keys** drive numbering and statuses and are the same strings the configuration screens use:

```
deliverable · drawing · rfi · submittal · change_request
hse_incident · investigation · corrective_action · hse_observation · hse_risk
hse_inspection · toolbox_talk · permit · hse_training
quality_inspection · ncr
```

---

## 3. The spawn contract — how an incident opens an investigation

This is the Phase 4 exit criterion and the single most important thing in this document.

```
                 configuration only, no code
                 ───────────────────────────

  hse_incident ──▶ workflow "Incident review"
                     └── stage "HSE manager review"
                           ├── auto-action: create_record → investigation      (14 days, role)
                           └── auto-action: create_record → corrective_action  (7 days, user)
```

Three pieces, and none of them names a module:

1. **`AutoActionRunner::createRecord()`** (Engine 2) resolves the `record` key through the morph map
   — `'investigation' => Investigation::class` in `AppServiceProvider` — and asks the class. It
   refuses two things outright rather than trusting configuration to be sensible: a record may not
   spawn its own kind, and it may not spawn the kind it was itself spawned from. Both are how a
   mis-drawn pair of routes becomes an unbounded chain of records at three in the morning.

2. **`SpawnsFromRecord`** is the interface a model implements to declare itself openable:

   ```php
   public static function spawnFrom(Model $source, array $params, ?int $assigneeId, User $actor): ?Model;
   public static function spawnLabel(): string;
   ```

   Returning `null` means "the module decided one was not wanted" — already open, or not applicable
   under this company's rules. That is a silent, correct outcome, and it is where **idempotence**
   lives: `Investigation::spawnFrom()` returns `null` if the incident already has one. A stage is
   re-entered every time a report is sent back and resubmitted, so this check is not optional.

3. **The builder's picker is discovered the same way.** The `create_record` action offers exactly
   the record types that implement the interface. Adding a spawnable type in Phase 5 is one
   interface on one model — no engine change, no new verb, no listener.

The auto-action shape stored on the stage:

```php
[
    'verb'          => 'create_record',
    'record'        => 'investigation',
    'title'         => 'Investigation into the reported incident',
    'due_in_days'   => 14,
    'assignee_type' => 'role',
    'assignee_ref'  => '8',
]
```

`assignee_type` / `assignee_ref` go through `AssigneeResolver`, the same resolver an approval stage
uses to decide who may act. `due_in_days` is turned into a date by the receiving service, not by the
engine, because "due" means something slightly different on each register.

---

## 4. One corrective-action register, many sources

`corrective_actions.source_type` / `source_id` is a morph, and it is the reason there is one
"what is outstanding against us" list rather than four:

```
hse_incident ──┐
investigation ─┼──▶ corrective_actions
hse_inspection ┤
ncr ───────────┘
```

An HSE manager asking "what is still open" gets one answer covering actions raised by an incident
review, by an investigation's recommendations, by a failed site inspection and by a non-conformance.
Four separate `*_actions` tables would have made that question unanswerable without a union, and
would have made "who is carrying the most open actions" unanswerable at all.

---

## 5. Segregation of duties is enforced in the service

Policies decide **who may reach a button**. Services decide **whether this particular person may
press it on this particular record**, and that second question is not a permission — it depends on
who created, requested or performed the record, so no role grant can express it. Every rule below
throws a `DomainException` from the service, independent of the policy:

| Register | The rule | Why |
|---|---|---|
| `permits` | The issuer may not be the requester **or** the creator | A permit issued by the person who asked for it is a note to self |
| `permits` | The acceptor may not be the issuer | Acceptance is the receiving party confirming the conditions |
| `permits` | Required precautions must be confirmed before issue | An unticked required precaution is the permit's whole point |
| `permits` | A gas test must exist where the type requires one | The type is configuration; the requirement is not negotiable per permit |
| `permits` | Suspending, refusing or cancelling must say why | A permit register with unexplained states is unusable in an inquiry |
| `corrective_actions` | The verifier may not be the assignee or the completer | Self-verification is the most common way a closed action stays open |
| `corrective_actions` | `ineffective` requires notes | "It did not work" without a reason produces the same action again |
| `quality_inspections` | The inspector may not be the creator | The person who requested the inspection is usually the one being inspected |
| `quality_inspections` | Anything other than a clean pass requires comments | A bare `failed` cannot be acted on |
| `ncrs` | The dispositioner may not be the detector or the creator | The disposition is the concession; the raiser cannot grant it to themselves |
| `ncrs` | `repair` / `use_as_is` / `regrade` require notes | These three are concessions and are exactly what an auditor reads |
| `investigations` | Cannot complete without a root cause | An investigation that stops at the immediate cause produces "remind operatives to clip on" |
| `incidents` | Cannot close while its investigation is incomplete | Closing over an open investigation is how an incident disappears |

Terminal statuses are deliberately not uniform: a corrective action ends `verified`, `ineffective`
or `cancelled` — there is no `closed`, because "closed" would hide the difference between an action
that worked and one that did not.

---

## 6. Immediate cause and root cause are separate columns

`investigations` carries both, and the separation is the entire discipline rather than a schema
nicety. "The operative did not clip on" is an immediate cause, and an investigation that stops there
produces a corrective action reading "remind operatives to clip on" — after which the same incident
happens again. The second column is what forces the second question, and `complete()` refuses to run
without it.

---

## 7. Confidentiality (§68)

Every register carries `confidentiality`, cast to `App\Support\Enums\Confidentiality`, and it is
what the notification gate and `ScopeCompiler` both read.

**It is stamped on the model at creation, never left to the column default.** The database would
apply `internal` on insert quite happily, but the model in memory would then carry no
`confidentiality` key at all, and everything downstream reads the model rather than the row:

- `NotificationDispatcher` skips the confidentiality gate entirely for a subject whose classification
  it cannot see — so a record created in the same request that notified about it was ungated;
- `SubmittalService::resubmit()` copies the parent's classification onto the next attempt, which
  meant copying a `null` onto a `NOT NULL` column.

The second failed loudly and only for callers that never reload — a seeder, an import, a queued job.
Through HTTP, route model binding handed over a freshly loaded record and hid it completely.
`RegisterService::stampConfidentiality()` now sets it explicitly; the check is column-gated (a few
records take their visibility entirely from a parent and have no such column) and the column listing
is cached per table.

**Medical detail lives on `incident_persons`, not on `incidents`.** Everybody who can see the
incident can see that somebody was hurt; a much smaller group can see the injury type, the body part,
the treatment and the return-to-work date. Those columns are writable only through
`IncidentService::addPerson()`, and no notification body carries them.

---

## 8. What is configuration, not code

| Surface | Where it lives | What changes without a developer |
|---|---|---|
| Permit types | `permit_types` rows | Which permits exist, their default duration, whether a gas test or an isolation is required, and the precaution template copied onto each new permit |
| Training courses | `training_courses` rows | Which tickets are tracked, their validity in months, whether they are mandatory |
| Statuses | `statuses`, per entity key | The status graph of every register, including which status is initial |
| Numbering | `NumberGenerator`, per entity key | Prefix, padding, reset period, per-project or per-organization sequences |
| Routes | `workflows` / `workflow_stages` | Whether an incident is reviewed at all, by whom, and what that review opens |
| Lists | organization settings | Submittal types and review codes, RFI kinds, investigation methods, observation categories |

`permit_types` and `training_courses` have **no service class**, and that is deliberate rather than
an omission: they are configuration, not records. Nothing numbers them, nothing routes them for
approval and nothing moves them through a status graph, so a `RegisterService` subclass would be
four abstract methods returning nothing. Their controllers construct the model, stamp the tenant and
record the activity.

**Two companies, one seeder.** `DemoSeeder` configures Meridian's incident route and reports a
lost-time injury against it, then fetches the spawned investigation and corrective action back out
of the database. Kestrel configures no incident route at all and closes its near miss by hand. Same
code, different data (§73).

---

## 9. Quality: inspection → NCR → disposition

```
quality_inspection ──▶ result: failed
        │
        └──▶ ncr  (quality_inspection_id)
               ├── issue        → the other party is told
               ├── respond      → they say what they propose
               ├── disposition  → rework · repair · use_as_is · reject · regrade
               ├── verify       → somebody who did not raise it confirms the fix
               └── close
```

`repair`, `use_as_is` and `regrade` are concessions — the work does not meet the specification and
is being accepted anyway — so all three require notes, and `cost_impact` exists because a concession
is usually the subject of a commercial conversation. `rework` and `reject` need none: they mean the
requirement still stands.

The NCR may also stand alone (`source` = audit, client, supplier, self-identified, test failure — a
configurable list, and what a supplier scorecard is built from) with no inspection behind it,
because plenty of non-conformances are found by somebody walking past.

---

## 10. Known limits

- The `create_record` picker offers only record types implementing `SpawnsFromRecord` — currently
  investigations and corrective actions — so "open a permit when an inspection fails" is a Phase 5
  interface, not a configuration option today.
- A corrective action's idempotence key is its configured title, so two actions with the same title
  on one stage collapse into one.
- `submitForApproval()` is wired to a route on incidents only; the other registers inherit the
  method but have no submit endpoint until a company asks for one.
- Risk scores are `likelihood × severity`, computed in the service from the two factors and never
  accepted from the form, with no configurable banding. A company
  that scores 4×4 or uses a named matrix has to read the numbers rather than the colour.
- Training expiry is computed from the course's `validity_months` at the moment the record is
  created. Changing a course's validity does not re-date the tickets already issued against it,
  which is correct — the certificate said what it said — but means a shortened validity has to be
  applied by renewing, not by editing.
