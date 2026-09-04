<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The catalogue: what a thing IS, said once, so every store agrees about it.
 *
 * A BALANCE OF A DESCRIPTION IS NOT A THING. Procurement runs on free text on purpose — a site
 * engineer types "12in CS flange, RF, 300#" and a supplier quotes against those words, and a system
 * that demands a catalogue code before anybody may ask for anything is a system people fill in
 * wrongly to get past. Stock is the opposite: you cannot count words. So `stock_item_id` is
 * optional on a requisition line and required on a material request line, and the moment a buyer
 * fills it in the two halves of the chain join up.
 *
 * `is_stock_item` IN A TABLE CALLED stock_items IS THE MOST USEFUL COLUMN HERE. Sand, diesel and
 * printer paper are bought constantly and counted never. This is where a company writes down which
 * of the things it buys it actually intends to track — and a false value means the receipt goes
 * straight to cost with no ledger row, which is the correct behaviour and not an omission.
 *
 * THE UNIT LIVES HERE AND NOWHERE ELSE. A movement does not get to claim it is in metres when the
 * item is counted in coils; that is how a store ends up holding 4,000 of something it has four of.
 * Procurement lines keep their own `uom` because a supplier sells in what a supplier sells in, and
 * reconciling the two is a conversation rather than a cast.
 */
class StockItem extends Model
{
    use RegisterRecord;

    protected $guarded = ['id'];

    /**
     * `stock_item.categories`.
     *
     * What a pipeline contractor sorts a store by and what a facilities company does are different
     * lists, and neither is the developer's business (§73). These are a starting point.
     */
    public const DEFAULT_CATEGORIES = [
        'Consumable', 'Spare part', 'Tool', 'Raw material', 'PPE', 'Electrical', 'Instrumentation',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'is_stock_item' => 'boolean',
            'is_hazardous' => 'boolean',
            'reorder_level' => 'decimal:4',
            'reorder_quantity' => 'decimal:4',
            'last_purchase_price' => 'decimal:4',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** @return HasMany<StockBalance,$this> */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<StockTransaction,$this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    /**
     * Requisition lines that named this item — the join that lets a buyer notice the yard already
     * has forty of what site is asking to buy.
     *
     * @return HasMany<PurchaseRequestItem,$this>
     */
    public function purchaseRequestItems(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * Nobody is assigned a catalogue entry.
     *
     * Stated rather than inherited: RegisterRecord resets the default to nothing, and a reader who
     * finds no declaration here should be able to tell that it was a decision. A user whose grant
     * is scoped to `assigned` sees no catalogue at all, which is right — the catalogue is either
     * yours to read or it is not.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['category', 'is_stock_item', 'is_hazardous'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Stock items';
    }

    // ------------------------------------------------------------------ state

    /**
     * Whether this may be asked for or ordered.
     *
     * `discontinued` is deliberately NOT the same as unusable: what is left on the shelf must still
     * be issuable until it runs out, and StockService checks `isStocked()` rather than this.
     */
    public function isRequestable(): bool
    {
        return $this->statusKey() === 'active';
    }

    /** Whether movements may name it at all — a discontinued line still has stock to run down. */
    public function isStocked(): bool
    {
        return (bool) $this->is_stock_item && $this->statusKey() !== 'draft';
    }

    /**
     * The total across every warehouse, from the balances.
     *
     * Requires `balances` to be loaded and says so by using the collection: called on a show page
     * where it already is, never in an index loop.
     */
    public function quantityOnHand(): float
    {
        return (float) $this->balances->sum(fn (StockBalance $balance) => (float) $balance->quantity_on_hand);
    }

    /**
     * Whether the total has fallen to the reorder point.
     *
     * False when no level was ever set, rather than true: an unset level means nobody has decided,
     * and a notice on every un-thought-about item is a notice everybody turns off.
     */
    public function isBelowReorderLevel(): bool
    {
        return $this->reorder_level !== null
            && $this->quantityOnHand() <= (float) $this->reorder_level;
    }

    /** @param  Builder<static>  $query */
    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        return blank($category) ? $query : $query->where('category', $category);
    }

    /** @param  Builder<static>  $query */
    public function scopeStocked(Builder $query): Builder
    {
        return $query->where('is_stock_item', true);
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['code', 'name', 'part_number', 'barcode'];
    }
}
