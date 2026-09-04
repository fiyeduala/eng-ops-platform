<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\MaterialRequest;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody asked the store for something and it now needs an answer.
 *
 * Separate from PurchaseRequestSubmitted even though the sentence is nearly the same, because the
 * two go to different people and mean different things: one asks the company to spend money, this
 * one asks a storekeeper to open a cage.
 */
class MaterialRequestSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MaterialRequest $materialRequest,
        public readonly ?int $actorId = null,
    ) {}
}
