<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\RecordExpiring;
use App\Models\ExpiryWatch;
use App\Models\Role;
use App\Support\ExpiryCatalogue;

/**
 * A watched date is close, or has gone by (key `record.expiring`).
 *
 * ONE LISTENER FOR EVERY REGISTER. A permit, a training certificate and a warranty are all worded
 * here, so the §68 gates, the audience rules and the question of what a warning is allowed to say
 * are answered once rather than once per module by whoever wrote that module's sweep.
 *
 * NAMING SOMEBODY ON A WATCH DOES NOT MEAN THEY ARE TOLD. Every candidate assembled here is still
 * put through NotificationDispatcher, which drops anybody who could not open the record itself. A
 * watch on a confidential document therefore reaches fewer people than its configuration lists,
 * silently, and that is correct: the alternative is a warning that tells somebody a record exists
 * by naming it at them (§68).
 *
 * THE BODY NAMES THE RECORD AND THE DATE AND NOTHING ELSE. No values off the record, no attachment,
 * no scan of the certificate — those live behind a page that re-checks authorization when it is
 * opened, and a notification is delivered to more inboxes than a page is opened by (§67).
 */
class NotifyRecordExpiring
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function handle(RecordExpiring $event): void
    {
        $watch = $event->watch;
        $record = $event->record;

        $audience = $this->audienceFor($watch, $event);

        if ($audience->isEmpty()) {
            return;
        }

        $days = $event->daysRemaining;
        $label = method_exists($record, 'expiryLabel') ? $record->expiryLabel() : class_basename($record);

        $when = match (true) {
            $days < 0 => 'passed '.abs($days).' '.(abs($days) === 1 ? 'day' : 'days').' ago',
            $days === 0 => 'is today',
            default => 'is in '.$days.' '.($days === 1 ? 'day' : 'days'),
        };

        $this->dispatcher->dispatch(
            notification: NotificationMessage::make(
                key: 'record.expiring',
                // The company's own name for the watch leads the subject line, because "Permit
                // expiring" said to a company that calls them work clearances is the platform
                // talking over them (§63).
                title: ($days < 0 ? 'Overdue' : 'Due').': '.$watch->name.' — '.$label,
                body: $watch->name.' for '.$label.' '.$when.'.',
                actionUrl: ExpiryCatalogue::showRouteFor($record),
            ),
            audience: $audience,
            subject: $record,
            projectId: method_exists($record, 'expiryProjectId') ? $record->expiryProjectId() : null,
        );
    }

    /**
     * Everybody the watch asked for, before the gates get to them.
     *
     * Built as one Audience rather than several dispatches so that a person who is both the owner
     * and a member of the named role is told once. The dispatcher de-duplicates by user id, which
     * is what makes stacking these `or` clauses safe.
     */
    private function audienceFor(ExpiryWatch $watch, RecordExpiring $event): Audience
    {
        $audience = Audience::none();
        $record = $event->record;

        if ($watch->notify_followers) {
            $audience = $audience->orFollowers($record);
        }

        if ($watch->notify_owner && method_exists($record, 'expiryOwnerId')) {
            $ownerId = $record->expiryOwnerId();

            if ($ownerId !== null) {
                $audience = $audience->orUser($ownerId);
            }
        }

        foreach ($watch->notifyUserIds() as $userId) {
            $audience = $audience->orUser($userId);
        }

        // Stored as ids and spent as slugs. Ids because a role that is renamed is still the same
        // role and a watch should not quietly stop reaching it; slugs because that is the currency
        // the audience engine deals in. A role deleted out from under a watch resolves to nothing
        // here, which is a watch that reaches fewer people rather than an exception in a cron job.
        foreach ($this->roleSlugs($watch) as $slug) {
            $audience = $audience->orRole($slug);
        }

        // --- and then what the MODULE knows -------------------------------------------------
        //
        // Deliberately not gated by `notify_owner` or `notify_followers`. Those switches are about
        // the people an administrator can see and choose on the watch screen; these are the ones
        // only the register can work out — the driver of the truck, the custodian of the machine —
        // and a watch that was never shown them cannot meaningfully be said to have excluded them.
        // §68 still applies to every one of them, which is the protection that matters.

        if (method_exists($record, 'expiryAlsoTell')) {
            foreach ($record->expiryAlsoTell() as $userId) {
                if ($userId !== null) {
                    $audience = $audience->orUser((int) $userId);
                }
            }
        }

        if ($watch->notify_followers && method_exists($record, 'expiryRelatedRecords')) {
            foreach ($record->expiryRelatedRecords() as $related) {
                if ($related !== null) {
                    $audience = $audience->orFollowers($related);
                }
            }
        }

        if (method_exists($record, 'expiryOrgUnitId')) {
            $orgUnitId = $record->expiryOrgUnitId();

            if ($orgUnitId !== null) {
                $audience = $audience->orOrgUnit($orgUnitId);
            }
        }

        return $audience;
    }

    /**
     * @return array<int,string>
     */
    private function roleSlugs(ExpiryWatch $watch): array
    {
        $ids = $watch->notifyRoleIds();

        if ($ids === []) {
            return [];
        }

        return Role::query()->whereKey($ids)->pluck('slug')->all();
    }
}
