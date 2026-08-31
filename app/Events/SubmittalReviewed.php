<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Submittal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A package came back with a code on it.
 *
 * This is the moment the submitting side has been waiting on, and frequently the moment work is
 * either released or stopped. Fired once — a submittal cannot be reviewed twice, and a resubmittal
 * is a different record with its own review.
 */
class SubmittalReviewed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Submittal $submittal,
        public readonly ?int $actorId = null,
    ) {}
}
