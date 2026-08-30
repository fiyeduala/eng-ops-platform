<?php

declare(strict_types=1);

namespace App\Listeners\Concerns;

use App\Engines\Access\OrganizationContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Turn the actor id an event carries back into a User, safely.
 *
 * Every domain event in this application carries `?int $actorId` rather than a User, for the reason
 * recorded on TaskAssigned: a serialized model on a listener is a model nobody re-authorized. Which
 * leaves every listener with the same three-line job, and the same two ways to get it wrong.
 *
 * The first is `User::find($id)` — inside the request that works, because the global scope pins the
 * query to the signed-in tenant; from a queue worker or an artisan command it either fails closed or,
 * worse, resolves against whatever tenant happens to be bound.
 *
 * The second is `User::withoutGlobalScopes()->find($id)`, which resolves it against no tenant at all.
 * A user id is unique per installation, not per company, so that is a cross-tenant read waiting for
 * the day two companies' ids line up (§75).
 *
 * What is correct is both at once: drop the ambient scope and pin explicitly to the SUBJECT's
 * organization — the record the notification is about, which is the only tenant that can be right.
 * The causer is used for one thing (`except($causer)`, so nobody is told about their own action), so
 * a null here costs a redundant self-notification, never a disclosure.
 *
 * @property-read OrganizationContext $context
 */
trait ResolvesActor
{
    private function resolveActor(?int $actorId, Model $subject): ?User
    {
        if ($actorId === null) {
            return null;
        }

        $organizationId = $subject->getAttribute('organization_id');

        if ($organizationId === null) {
            return null;
        }

        return $this->context->withoutScope(fn () => User::query()
            ->where('id', $actorId)
            ->where('organization_id', $organizationId)
            ->first());
    }
}
