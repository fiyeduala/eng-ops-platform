<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MaterialRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A store request was answered — approved, cut down, or refused.
 *
 * "APPROVED FOR LESS" IS A DECISION SOMEBODY HAS TO HEAR ABOUT. A site that asked for two hundred
 * metres and is getting eighty has to know before the shift starts, and it is the commonest outcome
 * on a store request rather than an edge case — so the event covers it rather than firing only on
 * approval and refusal.
 *
 * The outcome is read off the record by the listener rather than carried here, so nothing can tell
 * one story while the register tells another.
 */
class MaterialRequestDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MaterialRequest $materialRequest,
        public readonly ?int $actorId = null,
    ) {}
}
