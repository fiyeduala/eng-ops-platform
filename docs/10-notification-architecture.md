# 10 — Notification Architecture

## 1. Single dispatcher, mandatory authorization filter (§68)

Nothing in the platform calls `Notification::send()` directly. Everything goes through:

```php
NotificationDispatcher::dispatch(
    notification: new DocumentAwaitingReview($document),
    audience: Audience::project($project)->orRole('Document Controller'),
    subject: $document,
    requiredAbility: 'view',
);
```

The dispatcher **removes every recipient who cannot `view` the subject** before any channel is
touched. A notification therefore cannot leak the existence or contents of a restricted record.

Two further rules:

- **Payload minimisation** — the body carries only what the recipient is authorized to see. Titles
  of confidential records are replaced with the number alone.
- **Link gating** — a deep link is included only if the recipient passes the policy at send time,
  and the link re-checks at click time.

## 2. Audiences (§34)

| Audience | Expands to |
|----------|------------|
| `user(s)` | named users |
| `role(r)` | active holders of role `r`, optionally narrowed by unit/project |
| `orgUnit(u)` | members of unit `u` and (optionally) its subtree |
| `project(p)` | active members of project `p` |
| `projectDepartment(p, u)` | members of `p` whose unit ⊆ `u` |
| `projectRole(p, r)` | members of `p` holding project role `r` |
| `followers(record)` | subscribers to that record |
| `assignees(stageInstance)` | resolved workflow assignees |
| `supervisorOf(user)` / `headOf(unit)` | hierarchy lookups |
| `organization()` | everyone in the org (requires `announcement.broadcast`) |

Audiences compose with `->or()` / `->except()` and always de-duplicate.

## 3. Channels

| Channel | V1 | Notes |
|---------|----|-------|
| In-app | ✅ | `notifications` table; bell + `/notifications`; polled |
| Email | ✅ | Laravel Mail over SMTP; queued |
| Web push | ⛔ | VAPID Web Push; `push_subscriptions` table; optional per user. **Deferred** — see §9 |
| SMS | ⛔ | interface present, no default provider |
| WebSocket realtime | ⛔ | V2 on VPS — the in-app channel already writes the row |

All channels are queued on the **database** queue (§81). Email failures retry with backoff and land
in `failed_jobs`; in-app delivery is never blocked by SMTP problems.

## 4. Preferences

```
notification_preferences  user_id, notification_key, in_app, email, push, digest
```

Users choose per notification type. **Mandatory** notifications (workflow assignment, HSE alert,
account security) cannot be disabled — only their channel mix. A `digest` option batches
low-priority items into a daily summary rather than per-event mail.

Quiet hours and the organization's working calendar suppress non-urgent email outside work hours;
urgent classes (HSE incident, permit expiry) ignore quiet hours. Suppressed means **deferred, never
dropped** — a notification the sender believes was delivered and never was is worse than a late one.

Quiet hours are an **organization** setting, not a personal one — `notifications.quiet_hours.enabled`
/ `.start` / `.end`, read through the Configuration engine against `general.timezone`. See §9.

## 5. Notification catalogue

| Key | Trigger | Audience | Urgency |
|-----|---------|----------|---------|
| `workflow.assigned` | stage entered | resolved assignees | high |
| `workflow.approved` / `.rejected` / `.revision_requested` | decision | owner + followers | high |
| `workflow.escalated` | SLA breach | escalation target | high |
| `task.assigned` / `.due_soon` / `.overdue` | task events | assignee (+ supervisor on overdue) | normal |
| `project.member_added` | membership | the member | normal |
| `project.memo_published` | memo | memo audience | normal |
| `announcement.published` | announcement | audience | normal |
| `document.submitted` / `.approved` / `.published` | document events | reviewers, followers | normal |
| `hse.incident_reported` | incident | HSE role + project management | **urgent** |
| `hse.corrective_action_overdue` | scheduler | owner, HSE Manager | high |
| `permit.expiring` | scheduler | holder, HSE Manager | high |
| `procurement.pr_awaiting_approval` | workflow | current approver | high |
| `meeting.invited` / `.reminder` / `.minutes_published` | meeting events | participants | normal |
| `leave.requested` / `.decided` | leave workflow | approver / requester | normal |
| `expiry.threshold_reached` | Expiry engine | owner + responsible role | high |
| `report.daily_executive` | scheduler | configured recipients | normal |
| `account.created` / `password.reset` | auth | the user | high |

The table above is the **design target**, spanning every phase. `App\Support\NotificationCatalogue`
holds only the keys that have a producer today: a preferences screen offering a switch for something
that never fires is a lie told in the interface, and indistinguishable from a feature that is quietly
broken. Later phases add their rows as they add their listeners. See §9 for what is live and for the
three key names that changed.

## 6. Delivery pipeline

```
Domain event
  → Listener builds Audience + Notification
  → NotificationDispatcher
       ├ expand audience
       ├ FILTER by policy (requiredAbility on subject)   ← the security boundary
       ├ apply user preferences + quiet hours
       ├ de-duplicate (same key + subject + user within window)
       └ queue per channel
  → Database queue → worker → in-app row / mail / push
```

De-duplication prevents notification storms when a record changes rapidly — the same key/subject for
the same user collapses within a configurable window.

## 7. In-app UX

Bell with unread count · grouped by urgency then time · mark read / mark all read · deep link that
re-authorizes on click · filter by module · `/notifications` full history with search. Unread count
is cached and invalidated on write to avoid a count query on every page load.

## 8. Test obligations

| Test | Assertion |
|------|-----------|
| Confidential leak | A user without `view` on a confidential document receives **no** notification about it |
| Project scoping | A project-wide memo reaches only active project members |
| Expired membership | A member whose `ends_on` has passed receives nothing |
| Payload minimisation | Notification body contains no restricted field values |
| Link gating | Deep link returns 403/404 for a recipient who lost access between send and click |
| Mandatory classes | Workflow-assignment email cannot be disabled entirely |

All six are covered by `tests/Feature/Notifications/NotificationDispatchTest.php`, against Task as the
subject — plus tenant isolation of the audience, exclusion of the causer, refusal of a disabled
account, and the IDOR walk over another person's notification ids.

## 9. As built — Phase 2

The engine landed with the security boundary complete and three delivery mechanisms deferred. What
follows is the difference between this document and the code, recorded here so the next phase reads
the deviation rather than rediscovering it.

**Live.** `NotificationDispatcher` (expand → authorize per candidate against the subject's policy →
minimise → preferences → in-app row and/or queued mail), `Audience` with eleven rule kinds, the
`notifications` and `notification_preferences` tables, the bell and unread count, `/notifications`
with the read-time re-check, `/notifications/{id}/open` with the click-time re-check, the preferences
screen, and one producer: `NotifyTaskAssignee` on `TaskAssigned`.

**Catalogue keys — three deviations from §5.**

| §5 says | Code has | Why |
|---------|----------|-----|
| `project.memo_published` | `memo.issued` | The module is Memos, not Projects — a memo need not belong to a project at all, and the preferences screen groups by module |
| — | `meeting.rescheduled`, `meeting.cancelled` | A meeting moving or being called off is not the same event as being invited to it, and a person who mutes invitations still needs to know the room changed |
| `task.due_soon`, `task.overdue`, `meeting.reminder` | omitted | All three need a scheduler; nothing fires them yet, so no toggle is offered |

**Deferred, with the storage already in place.**

- *Web push* — the `push` column and its catalogue default exist; there is no VAPID transport and no
  `push_subscriptions` table, so the preferences screen does not render the toggle.
- *Digest* — the `digest` column exists and the dispatcher honours it (email is held back when set),
  but nothing assembles or sends the summary, so the toggle is not offered either. A person who could
  tick it would simply stop receiving that key.
- *De-duplication* (§6) — not implemented. Every dispatch writes a row. The window belongs with the
  scheduler work, and until a producer fires repeatedly for one record there is nothing to collapse.

**Listener registration.** `bootstrap/app.php` calls `withEvents(discover: false)` and the map lives
in `AppServiceProvider::configureEventListeners()`. Discovery would otherwise scan `app/Listeners`,
which means a file rename silently changes who gets told what — and a listener registered both ways
sends everything twice.

**No permission module.** Notifications have no entry in the permission catalogue and
`NotificationPolicy` does not extend `ScopedPolicy`. The rule is ownership and nothing else: a
department-scoped `notification.view` would be a licence to read other people's post, and every
notification is only ever a restatement of a record the manager can already open on their own
authority.
