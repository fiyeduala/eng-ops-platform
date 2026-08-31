<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Rfi;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A question that was asked has been answered.
 *
 * Fired on the FIRST answer only, never on an amendment. An amended answer is a correction to a
 * conversation that already happened and the people in it are already reading the record; a second
 * "your query has been answered" for the same query would teach them to ignore the first.
 *
 * Carries the actor as an id rather than a User for the reason recorded on TaskAssigned: a
 * serialized model on a listener is a model nobody re-authorized.
 */
class RfiAnswered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Rfi $rfi,
        public readonly ?int $actorId = null,
    ) {}
}
