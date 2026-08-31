<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ChangeRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody answered a change request — approved, rejected, deferred or withdrawn.
 *
 * All four dispositions fire this, not only approval. "Deferred" is the answer most often lost in a
 * spreadsheet, and a register that only announced approvals would be the thing losing it.
 *
 * Fired once. A decided request cannot be decided again, so there is no second announcement to
 * suppress.
 */
class ChangeRequestDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ChangeRequest $changeRequest,
        public readonly ?int $actorId = null,
    ) {}
}
