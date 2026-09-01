<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Permit;
use App\Models\PermitPrecaution;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The checklist that makes a permit mean something.
 *
 * CONFIRMING IS NOT EDITING, and this is the one child policy in the phase where that distinction
 * carries real weight. The parent freezes at issue, and the parent's `update` is therefore refused
 * from that moment — but confirmation happens BEFORE issue, by whoever verified the condition on
 * the ground, and they are frequently not the person who drafted the permit. So `confirm` is
 * authorized on its own terms: `permit.review` reach over the parent, refused once the permit is
 * issued because a precaution confirmed after issue is a precaution nobody issued against.
 *
 * ADDING AND REMOVING LINES follows the parent's `update`, which means the checklist is fixed at
 * issue along with everything else. An issuer who wants another precaution refuses the permit and
 * has it raised again — which is precisely the conversation that should happen.
 */
class PermitPrecautionPolicy extends ScopedPolicy
{
    protected string $module = 'permit';

    public function view(User $user, Model $record): bool
    {
        return $this->throughPermit($user, 'view', $record) ?? parent::view($user, $record);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->throughPermit($user, 'update', $record) ?? parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->throughPermit($user, 'update', $record) ?? parent::delete($user, $record);
    }

    public function createFor(User $user, Permit $permit): bool
    {
        return Gate::forUser($user)->allows('update', $permit);
    }

    /** Tick the line, having actually checked the thing. Only before issue; see the class docblock. */
    public function confirm(User $user, PermitPrecaution $precaution): bool
    {
        $permit = $this->permitFor($precaution);

        if ($permit === null || $permit->isIssued() || $permit->isClosedOut()) {
            return false;
        }

        return $this->check($user, 'review', $permit);
    }

    private function throughPermit(User $user, string $ability, Model $record): ?bool
    {
        if (! $record instanceof PermitPrecaution) {
            return null;
        }

        $permit = $this->permitFor($record);

        return $permit === null ? false : Gate::forUser($user)->allows($ability, $permit);
    }

    private function permitFor(PermitPrecaution $precaution): ?Permit
    {
        $permit = $precaution->relationLoaded('permit') ? $precaution->permit : $precaution->permit()->first();

        return $permit instanceof Permit ? $permit : null;
    }
}
