<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The store register: where stock is, and who answers for it.
 *
 * A WAREHOUSE IS NOT A DROPDOWN VALUE. It could have been — a `location` string on every stock
 * movement would have shipped a fortnight earlier — and it is a table because three questions are
 * asked of it that a string cannot answer: who is the keeper, may this store go negative, and is it
 * open for movements today. Each of those is a decision somebody makes and is accountable for.
 *
 * `close()` IS THE METHOD THAT NEEDS EXPLAINING. Closing a store that still holds stock is the
 * commonest way an inventory system starts lying: the balances stay on the books, nobody can see
 * them because the store has vanished from the pickers, and the valuation report keeps counting
 * them. So the close is refused while anything is on hand, and the message says what is in the way.
 * The way out is to transfer the stock somewhere real — which is a movement, in the ledger, with a
 * name against it — rather than to make a hundred thousand naira of cable disappear by a status
 * change.
 *
 * `suspend()` IS A DIFFERENT ACT AND HAS ITS OWN PERMISSION. A store is suspended for a stocktake:
 * ordinary movements stop so the count means something, but the count itself must still be
 * postable. StockService::assertMovementAllowed() is the other half — it lets `adjustment` and
 * `reversal` through a suspended store and refuses everything else. Folding `suspend` into
 * `warehouse.manage` would have meant that the ability to freeze every store in the company came
 * free with the ability to correct a typo in one store's address.
 *
 * THERE IS NO `delete`. The trait supplies soft deletes and the policy allows it; what there is no
 * service method for is deleting a store out from under a ledger that references it. See
 * `close()` — the intended end of a store's life is closed-and-empty, and the row stays
 * because every stock movement ever posted names it.
 */
class WarehouseService extends RegisterService
{
    protected function entityType(): string
    {
        return 'warehouse';
    }

    protected function modelClass(): string
    {
        return Warehouse::class;
    }

    /** The code is typed, not generated. See RegisterService::numberColumn(). */
    protected function numberColumn(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id'];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Warehouse $record */
        $record->code = $this->normaliseCode($record->code);
        $record->type = $this->type($attributes['type'] ?? null, $actor);

        // Off unless somebody asks for it, and asking for it is a decision with a consequence: a
        // store that may go negative will happily issue cable it does not have, and the balance
        // then reads as a debt to itself. Sites that issue faster than they post receipts genuinely
        // need it; nobody should get it by accident.
        if ($record->allow_negative_stock === null) {
            $record->allow_negative_stock = false;
        }
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        if (array_key_exists('code', $attributes)) {
            $attributes['code'] = $this->normaliseCode((string) $attributes['code']);
        }

        if (array_key_exists('type', $attributes)) {
            $attributes['type'] = $this->type($attributes['type'], $actor);
        }

        return parent::update($record, $attributes, $actor);
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Warehouse $record */
        return $actor->name.' opened the '.$record->name.' store ('.$record->code.').';
    }

    // ================================================================== standing

    /**
     * Bring the store into use.
     */
    public function activate(Warehouse $warehouse, User $actor): Warehouse
    {
        if ($warehouse->statusKey() === 'active') {
            return $warehouse;
        }

        return DB::transaction(function () use ($warehouse, $actor) {
            $this->moveTo($warehouse, 'active');
            $warehouse->save();

            $this->activity->record(
                $warehouse,
                'activated',
                $actor->name.' opened '.$warehouse->name.' for stock movements.',
                [],
                $actor,
            );

            return $warehouse;
        });
    }

    /**
     * Freeze ordinary movements, normally so a stocktake can be counted against something still.
     */
    public function suspend(Warehouse $warehouse, User $actor, ?string $reason = null): Warehouse
    {
        if ($warehouse->statusKey() !== 'active') {
            throw new \DomainException('Only a store that is in use can be suspended.');
        }

        return DB::transaction(function () use ($warehouse, $actor, $reason) {
            $this->moveTo($warehouse, 'suspended');
            $warehouse->save();

            $this->activity->record(
                $warehouse,
                'suspended',
                $actor->name.' froze stock movements at '.$warehouse->name.'.',
                array_filter(['reason' => $reason]),
                $actor,
            );

            return $warehouse;
        });
    }

    /**
     * Close the store for good — but only once there is nothing left in it.
     *
     * The refusal names how many lines are still holding stock rather than only saying no, because
     * "you cannot close this" with no further information is the point at which somebody starts
     * editing balances directly in the database.
     */
    public function close(Warehouse $warehouse, User $actor, ?string $reason = null): Warehouse
    {
        if ($warehouse->statusKey() === 'closed') {
            return $warehouse;
        }

        $holding = StockBalance::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('quantity_on_hand', '!=', 0)
            ->count();

        if ($holding > 0) {
            throw new \DomainException(
                $warehouse->name.' still holds stock on '.$holding.' '
                .($holding === 1 ? 'item' : 'items')
                .'. Transfer or issue it before closing the store.'
            );
        }

        return DB::transaction(function () use ($warehouse, $actor, $reason) {
            $this->moveTo($warehouse, 'closed');
            $warehouse->save();

            $this->activity->record(
                $warehouse,
                'closed',
                $actor->name.' closed the '.$warehouse->name.' store.',
                array_filter(['reason' => $reason]),
                $actor,
            );

            return $warehouse;
        });
    }

    // ================================================================== helpers

    /**
     * Upper case, trimmed, and single-spaced.
     *
     * Store codes are compared by eye across a delivery note, a shelf label and a screen, and
     * "main" beside "MAIN" is two stores as far as the unique index is concerned and one store as
     * far as everybody in the building is concerned.
     */
    private function normaliseCode(?string $code): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $code) ?? ''));
    }

    /** Always one of the company's own words (§84). */
    private function type(mixed $value, User $actor): string
    {
        $allowed = app(OptionList::class)->keys(
            'warehouse.types',
            Warehouse::DEFAULT_TYPES,
            $actor->organization_id,
        );

        $slug = blank($value) ? null : OptionList::slug((string) $value);

        if ($slug !== null && in_array($slug, $allowed, true)) {
            return $slug;
        }

        return in_array('store', $allowed, true) ? 'store' : (string) ($allowed[0] ?? 'store');
    }
}
