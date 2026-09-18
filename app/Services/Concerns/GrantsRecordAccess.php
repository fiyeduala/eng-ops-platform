<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\RecordPermission;
use App\Models\User;
use App\Support\Enums\AclEffect;
use App\Support\Enums\GranteeType;
use Illuminate\Database\Eloquent\Model;

/**
 * Writing Gate 4 rows: "this person, this record, because they were asked to work on it".
 *
 * WHY THIS EXISTS AT ALL. Gates 3 and 5 answer reach with a rule — your unit, your projects, your
 * assignments — and rules cannot express the two cases the People module is full of: the manager
 * reviewing somebody who does not sit in their department, and the investigator brought in from
 * another department precisely BECAUSE the case is not theirs. Both are asked to do a piece of work
 * on one restricted record, and the alternatives are worse in opposite directions — widen their
 * scope and they can read everybody's, leave it alone and they cannot open the thing they were
 * asked to write.
 *
 * WHAT A ROW CAN AND CANNOT DO. It lifts scope and confidentiality on ONE record. It does not grant
 * the module permission, which Gate 2 checks first and unconditionally — so a row written for
 * somebody with no permission on the module grants them exactly nothing. That is why `'*'` is a
 * defensible default here: it means "every ability they already hold, on this one record".
 *
 * REVOCATION IS PART OF THE PATTERN. A grant given for a piece of work is taken back when the work
 * moves on — a case reassigned, an investigation closed. Rows that are only ever written are how an
 * access model quietly becomes a list of everybody who has ever touched anything.
 */
trait GrantsRecordAccess
{
    protected function grantRecordAccess(
        Model $record,
        User $grantee,
        ?User $actor,
        string $reason,
        string $ability = '*',
    ): void {
        // §75, asserted rather than assumed: a grant across tenants would be the one way a Gate 4
        // row could reach past Gate 1, which nothing else in the platform can do.
        if ((int) $grantee->organization_id !== (int) $record->getAttribute('organization_id')) {
            throw new \DomainException('That person is not in this organization.');
        }

        RecordPermission::query()->updateOrCreate(
            [
                'record_type' => $record->getMorphClass(),
                'record_id' => $record->getKey(),
                'grantee_type' => GranteeType::User,
                'grantee_id' => $grantee->getKey(),
                'ability' => $ability,
            ],
            [
                // From the record, never from input (§74).
                'organization_id' => $record->getAttribute('organization_id'),
                'effect' => AclEffect::Allow,
                'granted_by' => $actor?->getKey(),
                'reason' => $reason,
                'expires_at' => null,
            ],
        );
    }

    protected function revokeRecordAccess(Model $record, int|string|null $userId): void
    {
        if ($userId === null) {
            return;
        }

        RecordPermission::query()
            ->forRecord($record)
            ->where('grantee_type', GranteeType::User)
            ->where('grantee_id', $userId)
            ->where('effect', AclEffect::Allow)
            ->delete();
    }
}
