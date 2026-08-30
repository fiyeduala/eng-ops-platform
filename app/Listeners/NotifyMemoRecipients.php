<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engines\Access\OrganizationContext;
use App\Engines\Notification\Audience;
use App\Engines\Notification\NotificationDispatcher;
use App\Engines\Notification\NotificationMessage;
use App\Events\MemoIssued;
use App\Listeners\Concerns\ResolvesActor;

/**
 * Tell the distribution list that a memo has been issued (key `memo.issued`).
 *
 * The audience is the distribution list and nothing else — not the author's department, not the
 * project team, not followers. A memo is a directed communication; broadcasting it to the people
 * around the recipients would defeat the only property that distinguishes it from an announcement.
 *
 * The list still goes through the dispatcher's authorization filter, which is not ceremony even
 * though MemoService has just written ACL rows for exactly these people. The two disagree in a real
 * case: a recipient whose account was suspended between drafting and issuing holds an ACL row and
 * fails Gate 0, and the filter is what stops the mail. Being ON a distribution list is not the same
 * fact as being able to read the memo, and only one of them is checked here (§68).
 *
 * The subject line carries the memo's subject, which is the author's own one-line summary and the
 * thing a recipient needs to triage it. The BODY is never quoted: it is long-form free text, and for
 * a restricted or confidential memo the dispatcher replaces even this with the number alone.
 */
class NotifyMemoRecipients
{
    use ResolvesActor;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly OrganizationContext $context,
    ) {}

    public function handle(MemoIssued $event): void
    {
        $memo = $event->memo;

        $recipientIds = $memo->recipients()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($recipientIds === []) {
            return;
        }

        // Explicit, because the event carries whatever the controller happened to have loaded and
        // strict mode turns an unloaded relation into a thrown exception mid-issue.
        $memo->loadMissing('issuer');

        $message = NotificationMessage::make(
            key: 'memo.issued',
            title: sprintf('%s — %s', $memo->number, $memo->subject),
            body: $this->body($memo->issuer?->name),
            actionUrl: route('memos.show', $memo, absolute: false),
        );

        $this->dispatcher->dispatch(
            notification: $message,
            audience: Audience::user($recipientIds),
            subject: $memo,
            requiredAbility: 'view',
            causer: $this->resolveActor($event->actorId, $memo),
        );
    }

    private function body(?string $issuer): string
    {
        return $issuer !== null
            ? 'Issued by '.$issuer.'. You are on the distribution list.'
            : 'You are on the distribution list.';
    }
}
