<?php

declare(strict_types=1);

namespace App\Engines\Notification;

use App\Support\NotificationCatalogue;

/**
 * What a notification SAYS, separated from who gets it and whether they may.
 *
 * A value object rather than a class per notification type. The framework convention — one
 * `DocumentAwaitingReview` class per event — buys polymorphic channel handling this system does not
 * use, because every channel here renders from the same three strings, and it costs a file per
 * sentence. The catalogue key already carries the identity that preferences and grouping hang off
 * (see NotificationCatalogue), so a class name would be a second, weaker identifier for the same
 * thing.
 *
 * Immutable on purpose. A listener builds one message and hands it to the dispatcher, which then
 * decides — per recipient, per channel — how much of it to actually say. If the message could be
 * mutated downstream, "the email said more than the bell did" would be a bug with no single place
 * to look for it.
 */
final class NotificationMessage
{
    private function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $actionUrl,
        public readonly ?string $urgencyOverride,
    ) {}

    /**
     * @param  string  $key  a NotificationCatalogue key
     * @param  string  $title  one line, as it appears in the bell
     * @param  string|null  $body  a sentence or two of context; never the record's whole contents
     * @param  string|null  $actionUrl  where to go to act on it; the route re-authorizes on arrival
     * @param  string|null  $urgency  overrides the catalogue, for the rare case where the same key
     *                                is routine most of the time and not this time
     */
    public static function make(
        string $key,
        string $title,
        ?string $body = null,
        ?string $actionUrl = null,
        ?string $urgency = null,
    ): self {
        return new self(
            key: $key,
            title: mb_substr(trim($title), 0, 200),
            body: $body === null ? null : trim($body),
            actionUrl: $actionUrl,
            urgencyOverride: $urgency,
        );
    }

    public function urgency(): string
    {
        $urgency = $this->urgencyOverride ?? NotificationCatalogue::urgencyOf($this->key);

        return in_array($urgency, NotificationCatalogue::urgencies(), true) ? $urgency : 'normal';
    }

    public function isUrgent(): bool
    {
        return $this->urgency() === 'urgent';
    }

    /**
     * The same notification with everything identifying removed except a reference (docs/10 §1).
     *
     * Used for the channels that leave the building. "Memo MEMO/2026/014 needs your attention" is
     * enough for the recipient to know to sign in and not enough for anyone reading over their
     * shoulder — or holding the mail server, or looking at a lock screen — to learn what it is
     * about. The recipient has already passed the authorization filter; this is about the copy of
     * the message that ends up somewhere the system does not control.
     */
    public function minimised(?string $reference): self
    {
        return new self(
            key: $this->key,
            title: $reference !== null
                ? $reference.' needs your attention'
                : 'You have a notification waiting',
            body: 'The details are withheld from email because this record is restricted. Sign in to read it.',
            actionUrl: $this->actionUrl,
            urgencyOverride: $this->urgencyOverride,
        );
    }

    /** The same message with no deep link — for a recipient the link would refuse. */
    public function withoutLink(): self
    {
        return new self(
            key: $this->key,
            title: $this->title,
            body: $this->body,
            actionUrl: null,
            urgencyOverride: $this->urgencyOverride,
        );
    }
}
