<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One handover. The record of who had what, when, and who said so.
 *
 * APPEND-ONLY, ENFORCED IN THE MODEL, for the reasons `StockTransaction` gives at length and for
 * one more that is specific to custody: this is the table somebody wants edited precisely when a
 * thing has gone missing. "It was signed back in" is a sentence that must cost a new row and a
 * timestamp, not a silent update (§59). The boot hooks below throw on any update and any delete,
 * from any caller — service, controller, tinker session or seeder — and there is no soft delete to
 * hide behind. A wrong handover is corrected by handing it back.
 *
 * BOTH ENDS ARE STORED, which looks like duplication of the previous row and is not. The row is the
 * DOCUMENT of a handover: it has to read as a complete sentence in a dispute months later, and the
 * first row of an asset's life has a `to` and no `from` at all. Only the `to_*` columns feed the
 * cache on `assets`.
 *
 * `authorised_by` IS NOT `created_by`, and the distinction is the whole control. A storekeeper types
 * the handover; a manager permits it. Where an organization does not separate the two they are the
 * same person and nothing is lost. Where it does — and every organization that has ever lost a
 * generator does — the difference is what makes the record worth having.
 *
 * NO `number`. A handover is quoted as "the transfer of AST-0042 on 3 March", never by a serial of
 * its own, and a generated series here would be a number nobody ever says out loud.
 */
class AssetCustodyTransfer extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /** What state a thing was handed over in. Configurable words; these are the starting point. */
    public const CONDITIONS = ['good', 'fair', 'poor', 'damaged'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    /**
     * The two hooks that make the docblock above true.
     *
     * `updating` rather than `saving`, so creating a row is untouched. Both throw rather than
     * returning false: a silent no-op leaves the caller believing the edit landed, which is worse
     * than the edit landing.
     */
    protected static function booted(): void
    {
        static::updating(function (self $transfer): void {
            throw new \LogicException(
                'Custody transfers cannot be edited. Record a new transfer against asset '
                .$transfer->asset_id.' instead.'
            );
        });

        static::deleting(function (self $transfer): void {
            throw new \LogicException(
                'Custody transfers cannot be deleted. Record a new transfer against asset '
                .$transfer->asset_id.' instead.'
            );
        });
    }

    // ------------------------------------------------------------------ relationships

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_custodian_id');
    }

    public function toCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_custodian_id');
    }

    public function fromOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'from_org_unit_id');
    }

    public function toOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'to_org_unit_id');
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function authoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------------ access hooks

    /**
     * The trail is governed by the asset's permissions; it is not a module of its own.
     *
     * `Scopeable::permissionModule()` would derive `asset_custody_transfer` from the class name and
     * then check `asset_custody_transfer.view` — a permission the catalogue deliberately does not
     * define, so every check would fail closed and the handover history would be invisible to
     * everybody including the two people named on it. There is exactly one right to read a custody
     * trail, and it is the right to read the asset.
     */
    public static function permissionModule(): string
    {
        return 'asset';
    }

    /**
     * Either end of the handover is "mine", plus whoever typed it.
     *
     * The person who gave a thing up has as much reason to see the record as the person who took
     * it — it is their evidence that they gave it up.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['from_custodian_id', 'to_custodian_id', 'authorised_by'];
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /**
     * The trail carries no owning department of its own — see the migration.
     *
     * Both `from_org_unit_id` and `to_org_unit_id` exist, and neither is "the department this
     * record belongs to": they are the two sides of a movement. Naming either would give one
     * department's manager sight of handovers away from them and not towards them, which is half a
     * trail and worse than none. Reach here follows the ASSET, through the policy.
     */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    /** Same argument as the org unit above: two sides, neither of them ownership. */
    public function accessProjectColumn(): ?string
    {
        return null;
    }

    // ------------------------------------------------------------------ state

    /** Whether this is the row that opened the asset's life — a `to` with no `from`. */
    public function isInitialAssignment(): bool
    {
        return $this->from_custodian_id === null
            && $this->from_org_unit_id === null
            && $this->from_project_id === null
            && $this->from_location_id === null;
    }

    /** Who or what received it, in words, whichever of the four ways it was handed over. */
    public function toLabel(): string
    {
        return $this->toCustodian?->name
            ?? $this->toProject?->name
            ?? $this->toOrgUnit?->name
            ?? $this->toLocation?->name
            ?? 'Unassigned';
    }

    /** Who or what gave it up, in words, or null where nobody held it before. */
    public function fromLabel(): ?string
    {
        return $this->fromCustodian?->name
            ?? $this->fromProject?->name
            ?? $this->fromOrgUnit?->name
            ?? $this->fromLocation?->name;
    }

    /** @param  Builder<static>  $query */
    public function scopeForAsset(Builder $query, int|string|null $assetId): Builder
    {
        return blank($assetId) ? $query : $query->where($this->qualifyColumn('asset_id'), $assetId);
    }
}
