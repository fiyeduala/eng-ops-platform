<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Document;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A reviewer sent a document back to its author.
 *
 * The reason travels with the event because it is the only part the author actually needs: a
 * notification saying "changes were requested" without saying what changed is a notification that
 * costs somebody a round trip to find out.
 */
class DocumentRevisionRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Document $document,
        public readonly ?string $reason = null,
        public readonly ?int $actorId = null,
    ) {}
}
