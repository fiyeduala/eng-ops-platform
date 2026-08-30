<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Memo;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A memo left the drafting stage and became a communication.
 *
 * Fired once, on the transition out of draft — never on a save, never on an edit, and never again
 * if somebody withdraws and re-issues, because MemoPolicy refuses to re-issue. That one-shot
 * property is what lets the listener treat "this event fired" as "these people have now been told",
 * which is the fact the whole module exists to record.
 *
 * Ids rather than User models, per the note on TaskAssigned: a listener re-resolves the actor and
 * re-checks every recipient at the moment it acts (§68).
 */
class MemoIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Memo $memo,
        public readonly ?int $actorId = null,
    ) {}
}
