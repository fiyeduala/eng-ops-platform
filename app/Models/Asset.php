<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One singular, identifiable thing the company owns.
 *
 * THE DIFFERENCE FROM A STOCK ITEM IS THE WORD "WHICH". Forty metres of cable is a balance; nobody
 * asks which forty. Generator SN-4471 is a row, and every question worth asking about it is about
 * that one — where it is, whose it is, when it was last serviced, what it has cost. A quantity
 * cannot carry a service history, which is why this is a register and not a flag on the catalogue.
 *
 * `custodian_id` AND THE THREE COLUMNS BESIDE IT ARE A CACHE. The truth is the newest row in
 * `asset_custody_transfers`, and `AssetService::transferCustody()` is the only thing that writes
 * either. `custodyMatchesTrail()` exists to be asserted in the tests rather than assumed — the same
 * arrangement, and the same proof obligation, as `StockBalance` against the stock ledger.
 *
 * FOUR WAYS TO HOLD SOMETHING AND NONE OF THEM MANDATORY. A person, a department, a project, a
 * place. A tower crane belongs to the Delta Terminal job and to no individual; a laptop is Chidi's;
 * a fire extinguisher belongs to the corridor it hangs in. Registers that force a person onto every
 * row all drift to the same place — everything assigned to the storekeeper, which assigns nothing
 * to anybody.
 *
 * THE CODE IS TYPED, NOT GENERATED, for the reason NumberGenerator gives about warehouses and
 * catalogue lines: generated numbers are for records that are EVENTS, typed codes are for things
 * that EXIST. A company that already has a plate riveted to every generator will type what the
 * plate says, and an `AST-2026-0001` beside it would be a number nobody uses.
 */
class Asset extends Model
{
    use HasCustomFields, RegisterRecord;

    /**
     * `asset.categories`.
     *
     * What a drilling contractor sorts a yard by and what a facilities company sorts an office by
     * are different lists, and neither is the developer's business (§73). A starting point.
     */
    public const DEFAULT_CATEGORIES = [
        'Plant and machinery', 'Vehicle', 'IT equipment', 'Office furniture',
        'Tools', 'Test equipment', 'Generator', 'Building',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'is_vehicle' => 'boolean',
            'acquired_on' => 'date',
            'warranty_expires_on' => 'date',
            'disposed_on' => 'date',
            'purchase_cost' => 'decimal:2',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The delivery line this arrived on — the chain, read backwards (§90).
     *
     * From here a reader reaches the receipt, the order, the quotation and the requisition that
     * started it, which is what turns "why do we own this" from a conversation into a query.
     */
    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }

    /** @return HasMany<AssetCustodyTransfer,$this> */
    public function custodyTransfers(): HasMany
    {
        // `id` breaks the tie, and it has to. Two handovers on the same day carry the same
        // `transferred_at` — a date, not a timestamp — and ordering on it alone leaves which of them
        // is "current" to whatever order the database felt like returning. The cache would then
        // agree with the trail on Tuesday and disagree on Wednesday, with nothing having changed.
        return $this->hasMany(AssetCustodyTransfer::class)->latest('transferred_at')->latest('id');
    }

    /** @return HasMany<AssetMaintenance,$this> */
    public function maintenances(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    /** @return HasOne<Vehicle,$this> */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The person holding it reaches it.
     *
     * This is what `assigned` scope means for an asset, and it is the rule that lets an
     * organization give every employee sight of the equipment they personally signed for without
     * giving them the register.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['custodian_id'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['category', 'is_vehicle'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Assets';
    }

    // ------------------------------------------------------------------ state

    /** Whether it is available to be used, issued or booked out. */
    public function isInService(): bool
    {
        return $this->statusKey() === 'in_service';
    }

    /**
     * Whether custody may move right now, and why not when it may not.
     *
     * Returns null when nothing is wrong, so callers read as `if ($reason = $asset->...)`. Mirrors
     * `Warehouse::movementRefusalReason()` and `Vendor::unusableReason()` for the same reason: a
     * service that throws "not permitted" leaves the person guessing which of four conditions they
     * tripped, and guessing is how the same refusal is reported as a bug three times.
     */
    public function custodyRefusalReason(): ?string
    {
        return match ($this->statusKey()) {
            'in_service', 'idle', 'under_maintenance' => null,
            'retired' => 'The asset has been retired and cannot be handed to anybody.',
            'lost' => 'The asset is recorded as lost. Reinstate it before transferring custody.',
            default => 'The asset is not yet in use.',
        };
    }

    /**
     * Whether the cached custody columns agree with the newest transfer.
     *
     * The cache-versus-truth proof, and the reason it is a method rather than a comment: a claim
     * that a derived column is rebuildable is worth exactly what the test asserting it is worth.
     * Requires `custodyTransfers` to be loaded, and says so by using the collection — this is
     * called from a show page and from the tests, never in an index loop.
     */
    public function custodyMatchesTrail(): bool
    {
        $newest = $this->custodyTransfers->first();

        if ($newest === null) {
            return $this->custodian_id === null
                && $this->org_unit_id === null
                && $this->project_id === null
                && $this->location_id === null;
        }

        return $this->custodian_id === $newest->to_custodian_id
            && $this->org_unit_id === $newest->to_org_unit_id
            && $this->project_id === $newest->to_project_id
            && $this->location_id === $newest->to_location_id;
    }

    /** Whose it is, in words, whichever of the four ways it is held. */
    public function custodyLabel(): string
    {
        return $this->custodian?->name
            ?? $this->project?->name
            ?? $this->orgUnit?->name
            ?? $this->location?->name
            ?? 'Unassigned';
    }

    /**
     * The soonest planned maintenance still outstanding, or null.
     *
     * Requires `maintenances` to be loaded. Deliberately ignores unplanned work: a breakdown that
     * has not been closed is not "due", it is happening.
     */
    public function nextMaintenanceDue(): ?AssetMaintenance
    {
        return $this->maintenances
            ->filter(fn (AssetMaintenance $m) => $m->is_planned && $m->due_on !== null && ! $m->isClosed())
            ->sortBy('due_on')
            ->first();
    }

    /** @param  Builder<static>  $query */
    public function scopeVehicles(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('is_vehicle'), true);
    }

    /** @param  Builder<static>  $query */
    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        return blank($category) ? $query : $query->where($this->qualifyColumn('category'), $category);
    }

    /**
     * Held by nobody at all — the tab an asset controller actually opens the register on.
     *
     * All four columns, not just the custodian: a thing sitting in a named yard is accounted for
     * even though no person signed for it, and reporting it as unassigned would bury the twelve
     * that genuinely are.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('custodian_id'))
            ->whereNull($this->qualifyColumn('org_unit_id'))
            ->whereNull($this->qualifyColumn('project_id'))
            ->whereNull($this->qualifyColumn('location_id'));
    }

    // ------------------------------------------------------------------ presentation

    /** An asset has a name, not a title. */
    public function registerTitleColumn(): string
    {
        return 'name';
    }

    public function displayName(): string
    {
        return trim($this->code.' — '.$this->name, ' —');
    }

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['code', 'name', 'serial_number', 'tag_number'];
    }

    /**
     * Nothing about an asset is due. Its MAINTENANCE is, and that register carries the date.
     */
    public function registerDueColumn(): ?string
    {
        return null;
    }
}
