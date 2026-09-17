<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The custody trail: written by whoever may transfer, read by whoever may see the asset, changed by
 * nobody at all.
 *
 * `protected string $module = 'asset'` AND `AssetCustodyTransfer::permissionModule()` SAY THE SAME
 * THING TWICE ON PURPOSE. There is no `asset_custody_transfer` module in the catalogue and there
 * should not be one: the right to read who has had a machine is the right to read the machine.
 * Two separate permissions would mean an administrator could hold one without the other, and every
 * combination of "may see the asset but not its history" or "may see the history but not the asset"
 * is a mistake somebody would eventually make. This class governs the HTTP door; the model's
 * override governs `visibleTo()` on the index query, and they have to agree.
 *
 * `create()` CHECKS `asset.transfer`, NOT `asset.create`. Writing a trail row IS the act of
 * transferring — there is no other way to do it, because the service writes the row and the cache in
 * one transaction. Anyone who may transfer may therefore create, and nobody else may, whatever the
 * shape of a posted form (§84).
 *
 * EVERY WRITE-AFTER-THE-FACT RETURNS FALSE UNCONDITIONALLY, matching StockTransactionPolicy and for
 * the same reason (§59). The model throws on update and delete, so this is the second of two locks;
 * it exists so that somebody holding `asset.manage` is refused at the door rather than discovering
 * the refusal as a 500. A custody record entered wrongly is corrected by transferring the asset
 * back, which is what happened in the real world anyway.
 */
class AssetCustodyTransferPolicy extends ScopedPolicy
{
    protected string $module = 'asset';

    /** Recording a handover is transferring. There is no second way to do it. */
    public function create(User $user): bool
    {
        return $this->check($user, 'transfer');
    }

    /**
     * A handover, once recorded, is a fact about the past.
     *
     * @param  Model  $record  Unused: no trail row is editable by anybody.
     */
    public function update(User $user, Model $record): bool
    {
        return false;
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function restore(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }
}
