<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TrainingRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Somebody's certificate.
 *
 * THE ONE REGISTER WHERE `own` SCOPE MEANS "ABOUT ME" RATHER THAN "ENTERED BY ME". TrainingRecord
 * overrides accessOwnerColumns() to include `user_id`, so an employee granted `hse_training.view` at
 * `own` scope sees their own tickets and nobody else's. That is the correct default for a record
 * about a person, and the reason it needed stating: the shared spine's "mine means I created it"
 * would have given the training administrator the whole register and the certificate holder nothing.
 *
 * READING YOUR OWN IS NOT EDITING YOUR OWN. The scope override cuts both ways, and it would be an
 * obvious hole if it did not: at `own` scope the holder would reach their own record for `edit` too,
 * and could adjust their own expiry date or mark themselves as having passed. `update` and `delete`
 * therefore refuse the holder outright unless they also hold `manage`, which is the permission a
 * training administrator has and an ordinary employee does not.
 *
 * A CERTIFICATE FILE IS A DOCUMENT (§67). `document_id` points into document control, and the
 * download authorizes against the document, not against this row.
 */
class TrainingRecordPolicy extends ScopedPolicy
{
    protected string $module = 'hse_training';

    public function update(User $user, Model $record): bool
    {
        if ($this->isOwnCertificate($user, $record) && ! $this->resolver->has($user, 'hse_training.manage')) {
            return false;
        }

        return parent::update($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        if ($this->isOwnCertificate($user, $record) && ! $this->resolver->has($user, 'hse_training.manage')) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /**
     * The record is about this person.
     *
     * Note that it asks about `user_id` only, not `created_by`: a training administrator who typed
     * in a colleague's certificate is not its subject, and must obviously still be able to correct
     * their own typing.
     */
    private function isOwnCertificate(User $user, Model $record): bool
    {
        return $record instanceof TrainingRecord
            && $record->user_id !== null
            && (int) $record->user_id === $user->getKey();
    }
}
