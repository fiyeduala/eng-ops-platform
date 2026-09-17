<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AssetCustodyTransfer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A piece of equipment changed hands.
 *
 * CARRIES THE TRANSFER, NOT THE ASSET, and the difference matters. The asset knows only where it is
 * now; the transfer knows where it came from, and both ends of a handover need telling — the person
 * receiving it because they are now answerable for it, and the person giving it up because this row
 * is their evidence that they did. An event carrying only the asset could not name the second
 * person without re-reading the ledger to find out who it used to belong to.
 *
 * MANDATORY IN THE NOTIFICATION CATALOGUE, unusually. Most notifications are a courtesy a user may
 * switch off; this one is a change in who is answerable for company property, and a custodian who
 * has muted it is a custodian who can later say nobody told them.
 */
class AssetCustodyTransferred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly AssetCustodyTransfer $transfer,
    ) {}
}
