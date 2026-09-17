<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may hold, hand over, and finally get rid of a company asset.
 *
 * THE THREE ABILITIES THAT ARE NOT `edit` ARE THE POINT OF THIS CLASS. An asset register that
 * governs everything with `asset.edit` is a register in which the storeman who corrects a spelling
 * mistake in a generator's model number is, by the same right, able to sign it over to himself and
 * then retire it. These are three different acts by three different kinds of person:
 *
 *   `assign`    — put a thing into somebody's hands for the first time.
 *   `transfer`  — move it from one pair of hands to another. Deliberately NOT `edit`, because the
 *                 custodian cache on the row is a CACHE: the truth is the append-only trail, and
 *                 the only lawful way to change it is to write another row on the end of it.
 *                 Anybody who could reach the cache through `edit` could rewrite history silently.
 *   `dispose`   — retire it, write it off, sell it. Deliberately NOT `delete`, because disposal is
 *                 the record's LAST CHAPTER rather than its erasure: the machine existed, it was
 *                 bought with somebody's money, and the trail of who had it must survive the skip.
 *                 `delete` still exists for the row typed in error an hour ago; the two are not the
 *                 same act and a company that conflates them cannot answer an audit.
 *
 * `delete()` IS NARROWED BELOW rather than left to the base class, because the base class would let
 * anybody with `asset.delete` soft-delete a machine with ten years of service history and a custody
 * trail naming eight people. The record with a trail is disposed of; only a childless row is
 * deleted. That check lives here and not only in the service, because the service is one door.
 */
class AssetPolicy extends ScopedPolicy
{
    protected string $module = 'asset';

    /**
     * An asset that has been anywhere cannot be deleted — only disposed of.
     *
     * The custody trail is append-only by construction (AssetCustodyTransfer refuses both update
     * and delete), so soft-deleting the parent would leave rows describing the movements of a thing
     * that officially never existed. A register whose history outlives its records is worse than one
     * that keeps a retired row, so the answer is: use `dispose`.
     */
    public function delete(User $user, Model $record): bool
    {
        if ($record instanceof Asset && $record->custodyTransfers()->exists()) {
            return false;
        }

        return parent::delete($user, $record);
    }

    /** Put an asset into somebody's hands for the first time. */
    public function assign(User $user, ?Asset $asset = null): bool
    {
        return $this->check($user, 'assign', $asset);
    }

    /** Hand it on. Writes a trail row; never edits the one before it. */
    public function transfer(User $user, ?Asset $asset = null): bool
    {
        return $this->check($user, 'transfer', $asset);
    }

    /** Retire, write off or sell. The end of the asset's life, not the end of its record. */
    public function dispose(User $user, ?Asset $asset = null): bool
    {
        return $this->check($user, 'dispose', $asset);
    }

    public function manage(User $user, ?Asset $asset = null): bool
    {
        return $this->check($user, 'manage', $asset);
    }
}
