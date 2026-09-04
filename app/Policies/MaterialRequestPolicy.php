<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may ask the store for something, who may agree, and who may hand it over.
 *
 * THREE ABILITIES FOR THREE DIFFERENT PEOPLE, and in a real store they usually are three people:
 * the foreman asks, the project manager agrees the job needs it, the storekeeper takes it off the
 * shelf. `issue` is separate from `approve` because the person who agreed the request should not
 * also be the person who says the goods went out — that is the same four-eyes argument procurement
 * makes about buying and receiving, applied to stock that is already in the building, where it is
 * if anything easier to walk off with.
 *
 * THE SEPARATION RULE ITSELF IS NOT HERE, for the reason PurchaseRequestPolicy gives: it depends on
 * `inventory.separate_approver_and_issuer` and on who approved this particular request, so
 * MaterialRequestService refuses the act and says why. A policy returning false would read as "you
 * do not have permission" to somebody who has exactly that permission and may simply not use it
 * here.
 *
 * A SUBMITTED REQUEST STOPS BEING THE REQUESTER'S, exactly as a purchase request does. The store
 * reads it, walks to the racking, and comes back to find the quantities changed — that is how a
 * store issues twelve against a request that now says two.
 */
class MaterialRequestPolicy extends ScopedPolicy
{
    protected string $module = 'material_request';

    /** A draft belongs to whoever wrote it; after that it belongs to the process. */
    public function update(User $user, Model $record): bool
    {
        if (! $record instanceof MaterialRequest) {
            return parent::update($user, $record);
        }

        if ($record->statusKey() === 'draft') {
            return parent::update($user, $record);
        }

        return $this->check($user, 'manage', $record);
    }

    public function submit(User $user, MaterialRequest $request): bool
    {
        return $this->check($user, 'submit', $request);
    }

    public function approve(User $user, MaterialRequest $request): bool
    {
        return $this->check($user, 'approve', $request);
    }

    /** Take it off the shelf and post the ledger rows. */
    public function issue(User $user, MaterialRequest $request): bool
    {
        return $this->check($user, 'issue', $request);
    }

    public function manage(User $user, MaterialRequest $request): bool
    {
        return $this->check($user, 'manage', $request);
    }

    /**
     * Refusing is the other half of deciding, so it is the same permission.
     *
     * There is no `material_request.reject` in the catalogue and there should not be: a store
     * request is agreed or it is not, by the same person answering the same question, and a
     * separate grant would let an administrator hand somebody the power to say yes but not no.
     * The status graph makes the same call — the reject transition is gated on
     * `material_request.approve`, and the two must not disagree.
     */
    public function reject(User $user, MaterialRequest $request): bool
    {
        return $this->check($user, 'approve', $request);
    }
}
