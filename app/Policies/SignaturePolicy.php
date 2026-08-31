<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Signature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Signatures (§37).
 *
 * A signature is a stronger claim than an approval. An approval says a decision was recorded; a
 * signature says a named person put their name to this exact revision — and the row carries a hash
 * of what was signed so that claim can be checked rather than trusted.
 *
 * ONLY THE SIGNATORY CAN SIGN, and only for themselves. There is no administrative override and no
 * permission that grants signing on somebody else's behalf, because a signature applied by an
 * administrator is a forgery with good intentions. The engine records `user_id` from the
 * authenticated actor, never from the request (§84).
 *
 * IMMUTABLE, like approvals and for the same reason. A signature that can be edited after the fact
 * proves nothing about what was signed. Revocation, when a module needs it, is a new row saying so
 * — never an update to the original.
 *
 * VISIBILITY follows the signed record: whoever may see the revision may see who signed it. That is
 * the point of a signature block.
 */
class SignaturePolicy extends ScopedPolicy
{
    protected string $module = 'document';

    public function view(User $user, Model $record): bool
    {
        if (! $record instanceof Signature) {
            return parent::view($user, $record);
        }

        if (! $this->sameOrganization($user, $record)) {
            return false;
        }

        if ((int) $record->user_id === (int) $user->getKey()) {
            return true;
        }

        $signable = $record->relationLoaded('signable') ? $record->signable : $record->signable()->first();

        return $signable instanceof Model && Gate::forUser($user)->allows('view', $signable);
    }

    /**
     * Signing is authorized against the RECORD being signed, not against this model.
     *
     * `create` is closed here so no controller can reach a bare "make a signature" path; the real
     * question — may this person sign this document — is asked of DocumentPolicy::sign(), which
     * knows what the record is and what state it is in.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }
}
