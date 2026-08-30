<?php

declare(strict_types=1);

namespace App\Engines\Notification;

use App\Engines\Access\OrganizationContext;
use App\Engines\Configuration\SettingsRepository;
use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Enums\Confidentiality;
use App\Support\NotificationCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The single way anything in this application tells anybody anything (docs/10 §1, §6).
 *
 * There is one dispatcher because there is one rule that must never be forgotten, and the only
 * reliable way to never forget a rule is to have exactly one place where it can be written:
 *
 *     A NOTIFICATION MAY NOT SAY ANYTHING TO SOMEBODY WHO COULD NOT HAVE READ IT THEMSELVES.
 *
 * That is §68, and it is the reason this class exists rather than each module calling Mail::send.
 * Notifications are where access control usually leaks, because they invert the normal direction of
 * data flow: everywhere else a person asks for a record and the system decides; here the system
 * volunteers it. A subject line is a disclosure. "Ngozi Eze was terminated — see the disciplinary
 * file" tells the reader everything even if the link 403s.
 *
 * So the audience is expanded into candidates, and then every candidate is put through the policy
 * for the subject BEFORE any channel is touched. Not the route, not the query — the policy, per
 * person, with the record in hand, at the moment of sending. Anyone it refuses is dropped silently,
 * because telling somebody that there is something they cannot be told about is itself a
 * disclosure.
 *
 * Three further reductions, in order (docs/10 §1):
 *
 *   PAYLOAD MINIMISATION — a recipient who passes the check still only gets the record's number by
 *   email when the record is restricted or confidential. They may read it; the mail server, the
 *   lock screen and the person behind them on the bus may not.
 *
 *   LINK GATING — the deep link is written only when the recipient would be let through it. The
 *   route re-checks on click anyway, which makes the stored link a courtesy rather than a
 *   permission; storing one that will 403 just teaches people the system is broken.
 *
 *   PREFERENCES AND QUIET HOURS — applied last, because they are the recipient's choice about
 *   delivery and must never be able to widen what was said, only narrow whether and when.
 *
 * What this class must never grow: a "notify everyone" shortcut that skips the subject, or a
 * `force` flag. Both have been the origin of every notification leak the author has met.
 */
class NotificationDispatcher
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Expand, filter, reduce, deliver.
     *
     * @param  NotificationMessage  $notification  what to say
     * @param  Audience  $audience  who it is aimed at — candidates, not recipients
     * @param  Model|null  $subject  the record it is about; the thing authorization is asked about
     * @param  string  $requiredAbility  the ability each candidate must hold over $subject
     * @param  User|null  $causer  who did the thing; never notified about their own action
     * @param  int|null  $projectId  denormalised for grouping; derived from the subject if omitted
     * @return int how many people were actually told
     */
    public function dispatch(
        NotificationMessage $notification,
        Audience $audience,
        ?Model $subject = null,
        string $requiredAbility = 'view',
        ?User $causer = null,
        ?int $projectId = null,
    ): int {
        if (! NotificationCatalogue::has($notification->key)) {
            // A listener naming a key nobody added to the catalogue is a bug about delivery, not
            // about safety. It is logged and sent as an ordinary notice rather than thrown, because
            // taking the request down would turn a missing catalogue row into a failed save of the
            // record the notification was about.
            Log::warning('Notification dispatched with an unknown catalogue key.', ['key' => $notification->key]);
        }

        $organization = $this->organizationFor($subject);

        if ($organization === null) {
            Log::warning('Notification dropped: no organization context.', ['key' => $notification->key]);

            return 0;
        }

        $candidateIds = $audience->except($causer)->resolve((int) $organization->getKey());

        if ($candidateIds === []) {
            return 0;
        }

        // Loaded inside the subject's tenant, so an id that somehow survived the audience's own
        // tenant pin still resolves to nothing here (§75).
        //
        // `organization` is eager-loaded because AccessResolver keys its permission cache on the
        // tenant's permissions_version. On a web request the middleware has already put that
        // relation on the signed-in user; these candidates are freshly queried strangers, and
        // without this every one of them would lazy-load — which outside production is a thrown
        // LazyLoadingViolationException, and in production is a query per recipient.
        $candidates = $this->context->within($organization, fn () => User::query()
            ->with('organization')
            ->whereIn('id', $candidateIds)
            ->get());

        $recipients = $this->authorized($candidates, $subject, $requiredAbility, $organization);

        if ($recipients === []) {
            return 0;
        }

        $preferences = $this->preferencesFor(array_keys($recipients), $notification->key, $organization);
        $projectId ??= $this->projectIdFor($subject);
        $reference = $this->referenceFor($subject);
        $minimise = $this->shouldMinimise($subject);

        $sent = 0;

        foreach ($recipients as $userId => $recipient) {
            $mix = NotificationPreference::resolve($preferences[$userId] ?? null, $notification->key);

            if (! $mix['in_app'] && ! $mix['email']) {
                continue;
            }

            if ($mix['in_app']) {
                $this->writeInApp($notification, $recipient, $subject, $causer, $organization, $projectId);
            }

            if ($mix['email'] && ! $mix['digest']) {
                $this->queueEmail(
                    $minimise ? $notification->minimised($reference) : $notification,
                    $recipient,
                    $organization,
                );
            }

            // The `push` channel is declared in the catalogue and stored in preferences, but no
            // web-push transport exists yet (docs/10 §3 lists VAPID as optional). Nothing is
            // attempted here rather than failing quietly per recipient; the preferences screen does
            // not offer the toggle until there is something behind it.

            $sent++;
        }

        return $sent;
    }

    /**
     * The unread count behind the bell.
     *
     * Cached briefly because it renders on every authenticated page, and invalidated on every write
     * below — so the number is either current or at most a minute stale, and a minute-stale count
     * on a bell is not a correctness problem. It is a COUNT, not content: nothing here needs
     * re-authorizing, because the rows were authorized when they were written and the list itself
     * is filtered again when it is opened.
     */
    public function unreadCountFor(User $user): int
    {
        return (int) Cache::remember(
            self::unreadCacheKey((int) $user->getKey()),
            60,
            fn () => Notification::query()->forUser($user)->unread()->count(),
        );
    }

    public static function forgetUnreadCount(int $userId): void
    {
        Cache::forget(self::unreadCacheKey($userId));
    }

    private static function unreadCacheKey(int $userId): string
    {
        return 'notifications:unread:'.$userId;
    }

    // ------------------------------------------------------------------ the security boundary

    /**
     * Gate 0 through Gate 5, asked once per candidate, with the record in hand.
     *
     * Asked through Gate::forUser rather than by re-running a scope query, so a notification and a
     * page load can never disagree: the same policy, the same five gates, the same confidentiality
     * rules and the same ACL rows. A candidate who passes here is a person who could open the
     * record right now; one who does not is dropped without trace.
     *
     * A null subject means the notification is about the account itself — a password reset, a
     * welcome message — and there is nothing to be authorized against. Those are addressed to named
     * users by construction, which is why this method is the only place the exemption exists and
     * why it cannot be reached by passing a flag.
     *
     * @param  Collection<int,User>  $candidates
     * @return array<int,User> keyed by user id
     */
    private function authorized(
        Collection $candidates,
        ?Model $subject,
        string $requiredAbility,
        Organization $organization,
    ): array {
        return $this->context->within($organization, function () use ($candidates, $subject, $requiredAbility) {
            $allowed = [];

            foreach ($candidates as $candidate) {
                if (! $candidate->canLogIn()) {
                    continue;
                }

                if ($subject !== null && ! Gate::forUser($candidate)->allows($requiredAbility, $subject)) {
                    continue;
                }

                $allowed[(int) $candidate->getKey()] = $candidate;
            }

            return $allowed;
        });
    }

    // ------------------------------------------------------------------ preferences

    /**
     * Everybody's stored choice for this one key, in a single query.
     *
     * One query rather than one per recipient: an announcement to a division is several hundred
     * people, and a per-recipient lookup there is several hundred round trips inside a loop that is
     * already doing a policy check each pass.
     *
     * A missing row is normal and means "never touched the settings screen" — it is not the same as
     * "switched everything off". NotificationPreference::resolve() is what turns absence into the
     * catalogue's defaults, and it is deliberately the only place that decision is made.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,NotificationPreference> keyed by user id
     */
    private function preferencesFor(array $userIds, string $key, Organization $organization): array
    {
        if ($userIds === []) {
            return [];
        }

        return $this->context->within($organization, fn () => NotificationPreference::query()
            ->whereIn('user_id', $userIds)
            ->where('notification_key', $key)
            ->get()
            ->keyBy(fn (NotificationPreference $preference) => (int) $preference->user_id)
            ->all());
    }

    // ------------------------------------------------------------------ channels

    private function writeInApp(
        NotificationMessage $notification,
        User $recipient,
        ?Model $subject,
        ?User $causer,
        Organization $organization,
        ?int $projectId,
    ): void {
        $this->context->within($organization, function () use ($notification, $recipient, $subject, $causer, $organization, $projectId) {
            Notification::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $recipient->getKey(),
                'key' => $notification->key,
                'urgency' => $notification->urgency(),
                'title' => $notification->title,
                'body' => $notification->body,
                'action_url' => $notification->actionUrl,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'causer_id' => $causer?->getKey(),
                'project_id' => $projectId,
            ]);
        });

        self::forgetUnreadCount((int) $recipient->getKey());
    }

    /**
     * Queue the email, deferring it past quiet hours unless it is urgent.
     *
     * Deferred rather than dropped. A notification suppressed at 23:00 and never sent is a
     * notification the sender believes was delivered — the worst of both outcomes. `later()` puts
     * it on the same database queue with a delay, so the worker picks it up when the company is
     * awake (docs/10 §4).
     */
    private function queueEmail(NotificationMessage $notification, User $recipient, Organization $organization): void
    {
        if (blank($recipient->email)) {
            return;
        }

        $mail = new NotificationMail(
            subjectLine: $notification->title,
            heading: $notification->title,
            bodyText: $notification->body,
            actionUrl: $notification->actionUrl !== null ? url($notification->actionUrl) : null,
            actionLabel: 'Open it in '.config('app.name'),
            organizationName: (string) $organization->name,
        );

        $sendAt = $notification->isUrgent() ? null : $this->quietHoursDelayFor($organization);

        $pending = Mail::to($recipient->email);

        $sendAt === null ? $pending->queue($mail) : $pending->later($sendAt, $mail);
    }

    /**
     * When quiet hours are in force, the moment they end. Null means "send now".
     *
     * Configuration, not code (§72): a company that works nights sets its own window, and one that
     * wants mail at any hour switches it off. The window may wrap midnight, which is the normal
     * case and the one a naive `>= start && <= end` comparison gets wrong.
     */
    private function quietHoursDelayFor(Organization $organization): ?Carbon
    {
        $organizationId = (int) $organization->getKey();

        if (! $this->settings->bool('notifications.quiet_hours.enabled', false, $organizationId)) {
            return null;
        }

        $timezone = $this->settings->string('general.timezone', config('app.timezone'), $organizationId);
        $start = $this->settings->string('notifications.quiet_hours.start', '20:00', $organizationId);
        $end = $this->settings->string('notifications.quiet_hours.end', '07:00', $organizationId);

        try {
            $now = Carbon::now($timezone);
            $startsAt = Carbon::parse($start, $timezone)->setDateFrom($now);
            $endsAt = Carbon::parse($end, $timezone)->setDateFrom($now);
        } catch (\Throwable) {
            // A malformed window must not stop the post. Better a mail at 3am than no mail.
            return null;
        }

        $wraps = $startsAt->greaterThan($endsAt);

        if ($wraps) {
            if ($now->greaterThanOrEqualTo($startsAt)) {
                return $endsAt->addDay();     // late evening — deliver tomorrow morning
            }

            if ($now->lessThan($endsAt)) {
                return $endsAt;               // small hours — deliver this morning
            }

            return null;
        }

        return $now->betweenIncluded($startsAt, $endsAt) ? $endsAt : null;
    }

    // ------------------------------------------------------------------ subject introspection

    /**
     * Whether the outbound copy has to be stripped back to a reference.
     *
     * Keyed off the record's own confidentiality, so it needs no per-module knowledge: a model
     * without the column is ordinary and says what it means. `restricted` is included alongside
     * `confidential` because the distinction between them is about who may reach the record, and
     * email reaches further than either.
     */
    private function shouldMinimise(?Model $subject): bool
    {
        if ($subject === null || ! array_key_exists('confidentiality', $subject->getAttributes())) {
            return false;
        }

        $value = $subject->getAttribute('confidentiality');
        $value = $value instanceof Confidentiality ? $value : Confidentiality::tryFrom((string) $value);

        return in_array($value, [Confidentiality::Restricted, Confidentiality::Confidential], true);
    }

    /** The record's own reference, for a message that may not name it. */
    private function referenceFor(?Model $subject): ?string
    {
        $number = $subject?->getAttribute('number');

        return is_string($number) && $number !== '' ? $number : null;
    }

    private function projectIdFor(?Model $subject): ?int
    {
        if ($subject === null) {
            return null;
        }

        if ($subject->getMorphClass() === 'project') {
            return (int) $subject->getKey();
        }

        $projectId = $subject->getAttribute('project_id');

        return $projectId === null ? null : (int) $projectId;
    }

    /**
     * The tenant this send belongs to: the subject's, or the bound context's.
     *
     * The subject wins when there is one, because a notification about a record belongs to that
     * record's company whatever the sending request thought it was doing.
     */
    private function organizationFor(?Model $subject): ?Organization
    {
        $organizationId = $subject?->getAttribute('organization_id');

        if ($organizationId !== null) {
            $bound = $this->context->get();

            if ($bound !== null && (int) $bound->getKey() === (int) $organizationId) {
                return $bound;
            }

            return $this->context->withoutScope(
                fn () => Organization::query()->find((int) $organizationId)
            );
        }

        return $this->context->get();
    }
}
