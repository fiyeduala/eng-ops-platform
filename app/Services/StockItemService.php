<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\User;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The catalogue: the list of things the company keeps, buys and issues.
 *
 * THE CATALOGUE IS THE SHARED VOCABULARY OF THE WHOLE CHAIN, which is the only reason it is a
 * register rather than a free-text description on a purchase-request line. Part 2 shipped those
 * lines with `description` and nothing else, deliberately, and this part adds the optional
 * `stock_item_id` beside it — so a requisition for "4 core 16mm armoured cable" and a receipt of
 * "XLPE SWA 4C 16" can be the same thing, and the store can answer "we already hold 300 metres of
 * that" before anybody raises an order at all. That answer is the §90 payoff and it is impossible
 * without a shared identifier.
 *
 * `discontinue()` RATHER THAN DELETE, and the distinction is the same one the store register
 * makes. Deleting a stock item breaks every historical movement that names it; discontinuing it
 * keeps the history readable and stops anybody ordering more. The stock already on the shelf is
 * still there and still valued — a discontinued item with 40 metres on hand is a completely normal
 * state and the reason `isStocked()` and `isRequestable()` are two different questions.
 *
 * THE UNIT OF MEASURE BELONGS TO THE ITEM AND IS NOT EDITABLE HERE ONCE MOVEMENTS EXIST. Changing
 * cable from metres to rolls silently restates every balance and every historical issue by whatever
 * the conversion happens to be, and there is no honest way to do it in an UPDATE. The refusal names
 * the ledger, because a storekeeper who set the unit up wrongly on day one needs to be told to
 * create the item again rather than left guessing.
 */
class StockItemService extends RegisterService
{
    protected function entityType(): string
    {
        return 'stock_item';
    }

    protected function modelClass(): string
    {
        return StockItem::class;
    }

    /** The code is typed, not generated. See RegisterService::numberColumn(). */
    protected function numberColumn(): ?string
    {
        return null;
    }

    /**
     * `last_purchase_price` is written by the goods-receipt chain, never by a form: it is a
     * statement about what was actually paid, and a field somebody can type is a field that says
     * what the person typing it wishes had been paid.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'last_purchase_price'];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var StockItem $record */
        $record->code = $this->normaliseCode($record->code);
        $record->category = $this->category($attributes['category'] ?? null, $actor);
        $record->uom = $this->normaliseUom($record->uom);

        // Stocked unless somebody says otherwise. The exception is real — a company may catalogue a
        // service or a made-to-order fabrication so that requisitions can name it consistently —
        // and StockService refuses to post movements against one, which is the whole meaning of
        // the flag.
        if ($record->is_stock_item === null) {
            $record->is_stock_item = true;
        }
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var StockItem $record */
        if (array_key_exists('code', $attributes)) {
            $attributes['code'] = $this->normaliseCode((string) $attributes['code']);
        }

        if (array_key_exists('category', $attributes)) {
            $attributes['category'] = $this->category($attributes['category'], $actor);
        }

        if (array_key_exists('uom', $attributes)) {
            $attributes['uom'] = $this->normaliseUom((string) $attributes['uom']);
            $this->assertUnitMayChange($record, $attributes['uom']);
        }

        return parent::update($record, $attributes, $actor);
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var StockItem $record */
        return $actor->name.' added '.$record->code.' — '.$record->name.' — to the stock catalogue.';
    }

    // ================================================================== standing

    /** Make the item orderable and issuable. */
    public function activate(StockItem $item, User $actor): StockItem
    {
        if ($item->statusKey() === 'active') {
            return $item;
        }

        return DB::transaction(function () use ($item, $actor) {
            $this->moveTo($item, 'active');
            $item->save();

            $this->activity->record(
                $item,
                'activated',
                $actor->name.' brought '.$item->code.' into use.',
                [],
                $actor,
            );

            return $item;
        });
    }

    /**
     * Stop anybody ordering or requesting more of it.
     *
     * Deliberately possible while stock is still on hand, and the activity entry says how much, so
     * that the store can see what it has to use up. Refusing to discontinue an item with a balance
     * would be the wrong control: the point of discontinuing is usually precisely that the last
     * drum is being used up and no more is coming.
     */
    public function discontinue(StockItem $item, User $actor, ?string $reason = null): StockItem
    {
        if ($item->statusKey() === 'discontinued') {
            return $item;
        }

        $onHand = (float) StockBalance::query()
            ->where('stock_item_id', $item->getKey())
            ->sum('quantity_on_hand');

        return DB::transaction(function () use ($item, $actor, $reason, $onHand) {
            $this->moveTo($item, 'discontinued');
            $item->save();

            $this->activity->record(
                $item,
                'discontinued',
                $actor->name.' discontinued '.$item->code.'.',
                array_filter([
                    'reason' => $reason,
                    'quantity_on_hand' => $onHand > 0 ? (string) $onHand : null,
                ]),
                $actor,
            );

            return $item;
        });
    }

    /**
     * What the last delivery of this actually cost. Called by the goods-receipt chain.
     *
     * A one-column write with no status change and no transaction, because it is a side note on a
     * catalogue entry rather than an event in the item's life — the event is the receipt, and the
     * receipt records itself. Silent on a non-positive price: a free-issue line and a line nobody
     * priced both arrive as zero, and neither is news about what the item costs.
     */
    public function recordPurchasePrice(StockItem $item, float $unitPrice): StockItem
    {
        if ($unitPrice <= 0) {
            return $item;
        }

        $item->forceFill(['last_purchase_price' => round($unitPrice, 2)])->save();

        return $item;
    }

    // ================================================================== helpers

    /**
     * The unit may be corrected while the item has never moved, and not afterwards.
     *
     * See the class docblock. The check is on the LEDGER rather than on the balance, because a
     * balance can be back at zero after a receipt and an issue and the history still reads in the
     * old unit.
     */
    private function assertUnitMayChange(StockItem $item, string $uom): void
    {
        if ($uom === (string) $item->getOriginal('uom')) {
            return;
        }

        if ($item->transactions()->exists()) {
            throw new \DomainException(
                'Stock has already been moved in '.$item->getOriginal('uom').', so the unit of measure '
                .'cannot be changed. Create the item again under the correct unit and discontinue this one.'
            );
        }
    }

    /** Upper case and single-spaced, for the same reason store codes are. */
    private function normaliseCode(?string $code): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $code) ?? ''));
    }

    /**
     * Trimmed, and nothing else.
     *
     * NOT slugged and NOT an option list, unlike categories. "m", "m2", "kg", "each", "roll" and
     * "litre" are typed by whoever knows the trade, and a company that measures in bags of cement
     * should not have to have "bag" added to a catalogue by an administrator first. The unit is
     * never compared against a fixed list anywhere in the platform — it is carried onto documents
     * and shown beside quantities, which is exactly what a free unit can do safely.
     */
    private function normaliseUom(?string $uom): ?string
    {
        $uom = trim((string) $uom);

        return $uom === '' ? null : $uom;
    }

    /** Always one of the company's own words (§84). */
    private function category(mixed $value, User $actor): ?string
    {
        $allowed = app(OptionList::class)->keys(
            'stock_item.categories',
            StockItem::DEFAULT_CATEGORIES,
            $actor->organization_id,
        );

        $slug = blank($value) ? null : OptionList::slug((string) $value);

        // Null is a legitimate answer here, unlike a warehouse type: an uncategorised item is
        // findable by code and by name, and forcing a category on a storekeeper entering forty
        // items produces forty items in whichever category was first in the list.
        return $slug !== null && in_array($slug, $allowed, true) ? $slug : null;
    }
}
