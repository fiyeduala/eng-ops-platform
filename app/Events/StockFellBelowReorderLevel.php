<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StockBalance;
use App\Models\StockTransaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A store's holding of one item just crossed its reorder level on the way down.
 *
 * CROSSED, NOT "IS BELOW". The distinction is the whole design. An item sitting under its level for
 * three weeks is a fact anybody can read off the stock page; an item that was above the line this
 * morning and is under it now is news, and only the movement that took it there knows that. Firing
 * on the state rather than on the crossing would mean every issue of an already-low item sent
 * another notice, and a storekeeper who is told the same thing eleven times stops reading any of
 * them — which is how a real shortage goes unnoticed.
 *
 * IT CARRIES THE MOVEMENT THAT CAUSED IT, so the notice can say what happened rather than only what
 * is true. "Four gaskets left after MR-2026-0042 was issued" is actionable; "four gaskets left" is
 * a number on a screen nobody was looking at.
 */
class StockFellBelowReorderLevel
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly StockBalance $balance,
        public readonly StockTransaction $transaction,
        public readonly float $level,
        public readonly ?int $actorId = null,
    ) {}
}
