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
| Web push | ✅ | VAPID Web Push; `push_subscriptions` table; optional per user |
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
urgent classes (HSE incident, permit expiry) ignore quiet hours.

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
