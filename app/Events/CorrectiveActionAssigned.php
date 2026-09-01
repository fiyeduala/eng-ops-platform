<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\CorrectiveAction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something has to be done, by somebody, by a date.
 *
 * The assignee is frequently NOT cleared to read the source record — a supervisor asked to fit a
 * guard rail has no business reading the injury detail of the incident that prompted it — which is
 * why CorrectiveActionService::openFrom() gives the action its own internal confidentiality instead
 * of inheriting the source's, and why this notification describes the action and never quotes what
 * it came from (§68).
 */
class CorrectiveActionAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CorrectiveAction $action,
        public readonly ?int $actorId = null,
    ) {}
}
