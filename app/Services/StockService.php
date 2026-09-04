<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Events\StockFellBelowReorderLevel;
use App\Models\StockBalance;
use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Quantity;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place stock moves. Everything else asks this.
 *
 * WHY THIS DOES NOT EXTEND RegisterService, which every other register in the platform does.
 * RegisterService stamps `status_id` on creation, and a ledger row has no status — it is a fact,
 * not a thing with a lifecycle. Inheriting to override half of it would leave a base class whose
 * central assumption is false for one subclass, which is worse than a hundred lines of its own. The
 * pieces that genuinely are shared — numbering, the activity trail, organization stamping — are
 * used here directly and behave identically.
 *
 * EVERY PUBLIC METHOD ENDS IN post(), AND post() IS THE ONLY THING THAT WRITES. Receiving, issuing,
 * transferring, adjusting and reversing differ in what they mean and not in what they do, so the
 * locking, the negative-stock guard, the average-cost arithmetic and the balance update are written
 * once. The alternative — five methods each doing their own arithmetic — is how a system ends up
 * where receipts update the average cost and returns do not.
 *
 * THE LOCK IS THE WHOLE CONCURRENCY DESIGN. `lockForUpdate()` on the balance row, inside the
 * transaction that writes the movement. Two storekeepers issuing the last four gaskets at the same
 * moment: the second waits, then reads a balance of zero, then is refused. Without it both read
 * four, both succeed, and the store owes somebody four gaskets it never had. This is not a
 * theoretical race — it is a Monday morning at a goods-out counter with two terminals.
 *
 * A SUSPENDED WAREHOUSE REFUSES MOVEMENTS BUT NOT ADJUSTMENTS, which looks inconsistent and is the
 * reason suspension exists. A store is frozen precisely so that a count can be taken and its
 * variances posted; if suspension blocked adjustments too, the stocktake could never be recorded
 * and everybody would simply not suspend the store. Receipts, issues and transfers stop. Counting
 * and correcting are the work.
 *
 * NOTHING HERE AUTHORIZES. Controllers do that, exactly as in procurement. What this class does
 * enforce is the pair of duty separations, because they depend on who did what to which record and
 * a policy cannot see that (see SeparatesDuties).
 */
class StockService
{
    use Concerns\SeparatesDuties;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Stock arriving: a delivery, a return from site, an opening balance.
     *
     * `unit_cost` MATTERS HERE AND NOWHERE ELSE. A receipt is the only movement that changes what
     * the stock on the shelf is worth, because it is the only one that adds stock bought at a
     * price. Everything else takes the average as it finds it.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function receive(
        Warehouse $warehouse,
        StockItem $item,
        float $quantity,
        User $actor,
        array $attributes = [],
    ): StockTransaction {
        return $this->post($warehouse, $item, 'receipt', abs($quantity), $actor, $attributes);
    }

    /**
     * Stock going out to a job, a person or a scrap bin.
     *
     * The quantity arrives positive from every caller and is negated here, so no caller can post an
     * issue that adds stock by passing a sign it did not mean to.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function issue(
        Warehouse $warehouse,
        StockItem $item,
        float $quantity,
        User $actor,
        array $attributes = [],
    ): StockTransaction {
        return $this->post($warehouse, $item, 'issue', -abs($quantity), $actor, $attributes);
    }

    /**
     * Stock coming back unused. An issue undone by events rather than by error.
     *
     * NOT A REVERSAL, and the distinction is worth the separate method. A reversal says the issue
     * should never have been recorded; a return says it was right at the time and the material came
     * back. An auditor reading a store's history needs to be able to tell those apart, and a system
     * that models returns as reversals tells them the storekeeper makes constant mistakes.
     *
     * It re-enters at the CURRENT average rather than at what it went out at, because the stock is
     * now indistinguishable from what is already on the shelf. Passing a `unit_cost` overrides that
     * for the case where it genuinely came back damaged and was revalued.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function returnToStore(
        Warehouse $warehouse,
        StockItem $item,
        float $quantity,
        User $actor,
        array $attributes = [],
    ): StockTransaction {
        return $this->post($warehouse, $item, 'return', abs($quantity), $actor, $attributes);
    }

    /**
     * Stock moving between two stores: two rows, one act, one transaction.
     *
     * THE PAIR IS WRITTEN ATOMICALLY OR NOT AT ALL. A transfer that half-succeeded — out of the
     * main store, never into the site store — is stock that has ceased to exist, and it is the
     * failure mode a transfer implemented as two separate calls produces the first time the second
     * one is refused. Both rows share a `transfer_ref` so the pair can always be found together.
     *
     * THE OUT SIDE GOES FIRST, deliberately: it is the one that can be refused for insufficient
     * stock, and failing before anything has been written is cheaper than rolling back.
     *
     * @param  array<string,mixed>  $attributes
     * @return array{0:StockTransaction,1:StockTransaction} out, then in
     */
    public function transfer(
        Warehouse $from,
        Warehouse $to,
        StockItem $item,
        float $quantity,
        User $actor,
        array $attributes = [],
    ): array {
        if ($from->getKey() === $to->getKey()) {
            throw new DomainException('Stock cannot be transferred to the store it is already in.');
        }

        $quantity = abs($quantity);
        $reference = (string) Str::uuid();

        return DB::transaction(function () use ($from, $to, $item, $quantity, $actor, $attributes, $reference) {
            $out = $this->post($from, $item, 'transfer_out', -$quantity, $actor, [
                ...$attributes,
                'transfer_ref' => $reference,
                'notes' => trim(($attributes['notes'] ?? '').' To '.$to->name),
            ]);

            // The receiving side takes the cost the stock left at, not the receiving store's
            // average: moving something between two shelves cannot change what it cost the company.
            $in = $this->post($to, $item, 'transfer_in', $quantity, $actor, [
                ...$attributes,
                'transfer_ref' => $reference,
                'unit_cost' => $out->unit_cost,
                'notes' => trim(($attributes['notes'] ?? '').' From '.$from->name),
            ]);

            return [$out, $in];
        });
    }

    /**
     * The stocktake: what was counted, against what the system believed.
     *
     * TAKES A COUNT, NOT A DIFFERENCE. The caller passes the number on the clipboard and this works
     * out the variance, because a storekeeper who has to compute the difference themselves will
     * eventually compute it backwards, and a system that accepts "-2" cannot tell the difference
     * between a count of thirty-eight and a typo.
     *
     * A COUNT THAT AGREES POSTS NOTHING AND IS STILL RECORDED. `last_counted_at` moves; no ledger
     * row is written, because nothing moved. That matters: a store where every count writes a
     * zero-quantity row is a ledger you cannot read, and "when was this last counted" is a question
     * the balance answers.
     *
     * THE DUTY SEPARATION IS HERE AND NOT ON THE POLICY. Whether the person posting a variance may
     * be the person who counted depends on a setting and on `last_counted_by`, which no policy can
     * see. `inventory.separate_counter_and_adjuster` governs, defaulting to on.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function adjust(
        Warehouse $warehouse,
        StockItem $item,
        float $countedQuantity,
        User $actor,
        string $reason,
        array $attributes = [],
    ): ?StockTransaction {
        if (trim($reason) === '') {
            throw new DomainException('A stock adjustment needs a written reason.');
        }

        return DB::transaction(function () use ($warehouse, $item, $countedQuantity, $actor, $reason, $attributes) {
            $balance = $this->lockBalance($warehouse, $item, $actor);

            $this->assertSeparateDuties(
                $balance->last_counted_by === null ? null : (int) $balance->last_counted_by,
                $actor,
                'inventory.separate_counter_and_adjuster',
                'The person who counted the stock may not also post the variance.',
            );

            $variance = round($countedQuantity - (float) $balance->quantity_on_hand, 4);

            $balance->forceFill([
                'last_counted_at' => now(),
                'last_counted_by' => $actor->getKey(),
            ])->save();

            if ($variance === 0.0) {
                $this->activity->record(
                    $balance,
                    'counted',
                    $actor->name.' counted '.Quantity::withUnit($countedQuantity, $item->uom)
                        .' of '.$item->name.' in '.$warehouse->name.', agreeing with the system.',
                    ['counted' => $countedQuantity, 'variance' => 0],
                    $actor,
                );

                return null;
            }

            return $this->post($warehouse, $item, 'adjustment', $variance, $actor, [
                ...$attributes,
                'reason' => $reason,
                'counted_quantity' => $countedQuantity,
            ]);
        });
    }

    /**
     * Undo a movement by posting its opposite.
     *
     * THE ONLY CORRECTION MECHANISM THERE IS. The model refuses updates and deletes outright (§59),
     * so this is not one option among several — it is the door, and it is why that refusal is
     * liveable. The new row carries `reverses_transaction_id`, so the pair reads as what happened:
     * forty posted at 14:32, taken back at 14:36, by these two people, for this reason.
     *
     * A REVERSAL CANNOT ITSELF BE REVERSED, and a movement cannot be reversed twice. The first
     * would produce a chain nobody can read; the second would take the stock away twice. Both are
     * refused with the reason rather than silently ignored.
     *
     * IT REVERSES THE QUANTITY, NOT THE VALUE AT TODAY'S AVERAGE. `unit_cost` is copied from the
     * original, because the point of a reversal is that the original should never have happened.
     */
    public function reverse(StockTransaction $transaction, User $actor, string $reason): StockTransaction
    {
        if (trim($reason) === '') {
            throw new DomainException('A reversal needs a written reason.');
        }

        if ($transaction->type === 'reversal') {
            throw new DomainException('A reversal cannot itself be reversed. Post a fresh movement instead.');
        }

        return DB::transaction(function () use ($transaction, $actor, $reason) {
            // Re-read under the lock rather than trusting the model handed in: two people opening
            // the same movement and both clicking reverse is exactly how stock gets taken twice.
            $existing = StockTransaction::query()
                ->where('reverses_transaction_id', $transaction->getKey())
                ->lockForUpdate()
                ->exists();

            if ($existing) {
                throw new DomainException('That movement has already been reversed.');
            }

            $warehouse = $transaction->warehouse()->firstOrFail();
            $item = $transaction->stockItem()->firstOrFail();

            return $this->post($warehouse, $item, 'reversal', -(float) $transaction->quantity, $actor, [
                'unit_cost' => $transaction->unit_cost,
                'project_id' => $transaction->project_id,
                'org_unit_id' => $transaction->org_unit_id,
                'source_type' => $transaction->source_type,
                'source_id' => $transaction->source_id,
                'reverses_transaction_id' => $transaction->getKey(),
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Hold stock that is still on the shelf, so it stops being offered to anybody else.
     *
     * A RESERVATION IS NOT A MOVEMENT, which is why this touches the balance and writes no ledger
     * row. Nothing has gone anywhere: an approved material request is a promise, and the material
     * is still countable, still visible and still physically present. Modelling it as a movement
     * into a "reserved" warehouse — which is how it is often done — makes a stocktake of the main
     * store come up short by everything anybody has promised.
     *
     * Negative deltas release. There is deliberately no `release()` twin: two methods that differ
     * by a sign drift apart, and the one that gets forgotten is the release.
     */
    public function reserve(Warehouse $warehouse, StockItem $item, float $delta, User $actor): StockBalance
    {
        return DB::transaction(function () use ($warehouse, $item, $delta, $actor) {
            $balance = $this->lockBalance($warehouse, $item, $actor);

            // Never below zero: a release of more than was ever held is a bug elsewhere, and
            // clamping keeps it from becoming stock that appears available out of nowhere.
            $reserved = max(0.0, (float) $balance->quantity_reserved + $delta);

            $balance->forceFill(['quantity_reserved' => $reserved])->save();

            return $balance;
        });
    }

    // ================================================================== the core

    /**
     * Write one ledger row and bring its balance with it. The only method here that writes.
     *
     * THE ORDER OF THE GUARDS IS THE ORDER A STOREKEEPER WOULD ASK THEM IN: is this store taking
     * movements, is this something we stock, is there enough of it. Each throws with a sentence
     * naming what is wrong, because "not permitted" at a goods-out counter is an answer that sends
     * somebody to find a manager who also cannot tell what happened.
     *
     * @param  array<string,mixed>  $attributes
     */
    private function post(
        Warehouse $warehouse,
        StockItem $item,
        string $type,
        float $quantity,
        User $actor,
        array $attributes = [],
    ): StockTransaction {
        return DB::transaction(function () use ($warehouse, $item, $type, $quantity, $actor, $attributes) {
            $this->assertMovementAllowed($warehouse, $item, $type);

            $balance = $this->lockBalance($warehouse, $item, $actor);

            $onHand = (float) $balance->quantity_on_hand;
            $after = round($onHand + $quantity, 4);

            if ($after < 0 && ! $warehouse->allow_negative_stock) {
                throw new DomainException(
                    $warehouse->name.' holds '.Quantity::withUnit($onHand, $item->uom).' of '.$item->name
                    .'. '.Quantity::withUnit(abs($quantity), $item->uom).' cannot be taken out of it.'
                );
            }

            // The cost the movement carries. Inbound at what was paid — falling back to the running
            // average when nobody said, which is what a transfer or a return normally wants —
            // outbound always at the average, because that is what leaving the shelf is worth.
            $average = (float) $balance->average_cost;
            $unitCost = $quantity > 0
                ? (float) ($attributes['unit_cost'] ?? $average)
                : $average;

            // WEIGHTED MOVING AVERAGE, and only on the way in. Value on hand plus value received,
            // over quantity on hand plus quantity received. An issue leaves it exactly where it was
            // — taking stock off a shelf cannot change what the rest of it cost.
            if ($quantity > 0 && $after > 0) {
                $average = round(((($onHand * $average) + ($quantity * $unitCost)) / $after), 4);
            }

            $transaction = new StockTransaction([
                'warehouse_id' => $warehouse->getKey(),
                'stock_item_id' => $item->getKey(),
                'type' => $type,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'value' => round($quantity * $unitCost, 2),
                'balance_after' => $after,
                'project_id' => $attributes['project_id'] ?? null,
                'org_unit_id' => $attributes['org_unit_id'] ?? null,
                'source_type' => $attributes['source_type'] ?? null,
                'source_id' => $attributes['source_id'] ?? null,
                'transfer_ref' => $attributes['transfer_ref'] ?? null,
                'reverses_transaction_id' => $attributes['reverses_transaction_id'] ?? null,
                'batch_ref' => $attributes['batch_ref'] ?? null,
                'expires_on' => $attributes['expires_on'] ?? null,
                'reason' => $attributes['reason'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'occurred_at' => $attributes['occurred_at'] ?? now(),
            ]);

            // Stamped, never filled from the payload — the same rule RegisterService works to.
            $transaction->setAttribute('organization_id', $actor->organization_id);
            $transaction->setAttribute('created_by', $actor->getKey());
            $transaction->setAttribute('number', $this->numbers->next('stock_transaction'));
            $transaction->save();

            $balance->forceFill([
                'quantity_on_hand' => $after,
                'average_cost' => $average,
                'total_value' => round($after * $average, 2),
                'last_movement_at' => $transaction->occurred_at,
            ])->save();

            $this->activity->record(
                $transaction,
                'posted',
                $this->narrate($transaction, $warehouse, $item, $actor),
                ['number' => $transaction->number, 'type' => $type, 'quantity' => $quantity, 'balance_after' => $after],
                $actor,
                $transaction->project_id,
            );

            /*
             * THE CROSSING, NOT THE STATE. `$reserved` is read from the row as it stood before this
             * movement, so both sides of the comparison use the same reservation and the only thing
             * that moved is the quantity. An item already under its level stays quiet; the movement
             * that takes it under speaks once.
             *
             * The item is attached to the balance first because effectiveReorderLevel() refuses to
             * lazy-load — it returns null rather than firing a query per row on an index page — and
             * a null level here would silently mean "never notify".
             */
            $balance->setRelation('stockItem', $item);
            $level = $balance->effectiveReorderLevel();

            if ($level !== null && $level > 0.0) {
                $reserved = (float) $balance->quantity_reserved;

                if (($onHand - $reserved) > $level && ($after - $reserved) <= $level) {
                    StockFellBelowReorderLevel::dispatch(
                        $balance, $transaction, $level, (int) $actor->getKey()
                    );
                }
            }

            return $transaction;
        });
    }

    /**
     * The balance row for this pair, locked, creating it the first time.
     *
     * THE CREATE IS THE RACE, not the read. Two movements of an item that has never been in this
     * store before both find nothing and both insert, and the unique index refuses the second —
     * which is the index doing its job. Catching that and re-reading is the correct recovery, and
     * the re-read then takes the lock the first writer is holding, so the two serialise exactly as
     * they would have if the row had already existed.
     */
    private function lockBalance(Warehouse $warehouse, StockItem $item, User $actor): StockBalance
    {
        $find = fn (bool $lock): ?StockBalance => StockBalance::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('stock_item_id', $item->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($balance = $find(true)) {
            return $balance;
        }

        try {
            $balance = new StockBalance([
                'warehouse_id' => $warehouse->getKey(),
                'stock_item_id' => $item->getKey(),
            ]);
            $balance->setAttribute('organization_id', $actor->organization_id);
            $balance->save();

            return $balance;
        } catch (QueryException $exception) {
            // Somebody else got there between the read and the insert. Their row is the right one.
            return $find(true) ?? throw $exception;
        }
    }

    /**
     * Why a movement is refused, asked in the order a person would ask it.
     *
     * ADJUSTMENTS AND REVERSALS SURVIVE SUSPENSION. See the class docblock: a store is frozen so
     * that a count can be posted, and a freeze that stopped the count would be a freeze nobody
     * would ever apply.
     */
    private function assertMovementAllowed(Warehouse $warehouse, StockItem $item, string $type): void
    {
        if (! in_array($type, ['adjustment', 'reversal'], true) && ! $warehouse->acceptsMovements()) {
            throw new DomainException(
                $warehouse->name.': '.($warehouse->movementRefusalReason() ?? 'movements are not accepted.')
            );
        }

        /*
         * THE STATUS COMES WITH THE ITEM, because isStocked() asks for it and the item almost
         * never arrives here on its own. It comes out of somebody else's eager load — the lines of
         * a goods receipt, the lines of a material request — assembled by a caller who was
         * thinking about quantities. Under `preventLazyLoading()` that reads as a violation and
         * the movement dies at the one guard that was supposed to explain itself in words.
         *
         * A no-op when the relation is already there, and one row when it is not, against a method
         * that is about to write two. The alternative is every present and future caller of this
         * service remembering a relation it does not itself use.
         */
        $item->loadMissing('status');

        if (! $item->isStocked()) {
            throw new DomainException(
                $item->name.' is not held in stock, so it cannot be moved through a store.'
            );
        }
    }

    /** What the activity trail says, in the words a storekeeper would use. */
    private function narrate(StockTransaction $transaction, Warehouse $warehouse, StockItem $item, User $actor): string
    {
        $quantity = Quantity::withUnit(abs((float) $transaction->quantity), $item->uom);

        return match ($transaction->type) {
            'receipt' => $actor->name.' received '.$quantity.' of '.$item->name.' into '.$warehouse->name.'.',
            'issue' => $actor->name.' issued '.$quantity.' of '.$item->name.' from '.$warehouse->name.'.',
            'return' => $actor->name.' returned '.$quantity.' of '.$item->name.' to '.$warehouse->name.'.',
            'transfer_out' => $actor->name.' transferred '.$quantity.' of '.$item->name.' out of '.$warehouse->name.'.',
            'transfer_in' => $actor->name.' transferred '.$quantity.' of '.$item->name.' into '.$warehouse->name.'.',
            'adjustment' => $actor->name.' adjusted '.$item->name.' in '.$warehouse->name.' by '
                .((float) $transaction->quantity > 0 ? '+' : '-').$quantity.'.',
            'reversal' => $actor->name.' reversed a movement of '.$quantity.' of '.$item->name.' in '.$warehouse->name.'.',
            default => $actor->name.' posted a movement of '.$quantity.' of '.$item->name.'.',
        };
    }

    /**
     * Rebuild every balance from the ledger. The claim in the migration docblock, in code.
     *
     * IF THIS METHOD COULD NOT BE WRITTEN, THE DESIGN WOULD BE WRONG. The whole justification for
     * caching balances is that the ledger remains the authority, and the only proof of that is a
     * routine that can throw the cache away and reconstruct it. It runs after a restore, after any
     * suspicion of drift, and — most usefully — in the test suite, where a ledger and a rebuilt
     * balance that disagree mean the arithmetic in post() is wrong.
     *
     * RESERVATIONS ARE NOT REBUILT HERE. They are not derived from movements — see reserve() — so a
     * rebuild leaves them alone rather than zeroing figures it cannot recompute.
     *
     * @return int the number of balance rows written
     */
    public function rebuildBalances(?Warehouse $warehouse = null): int
    {
        return DB::transaction(function () use ($warehouse) {
            $rows = StockTransaction::query()
                ->when($warehouse, fn ($query) => $query->where('warehouse_id', $warehouse->getKey()))
                ->orderBy('id')
                ->get(['id', 'organization_id', 'warehouse_id', 'stock_item_id', 'quantity', 'unit_cost', 'occurred_at']);

            /** @var array<string,array{organization_id:int,warehouse_id:int,stock_item_id:int,qty:float,avg:float,at:mixed}> $totals */
            $totals = [];

            foreach ($rows as $row) {
                $key = $row->warehouse_id.':'.$row->stock_item_id;
                $running = $totals[$key] ?? [
                    'organization_id' => (int) $row->organization_id,
                    'warehouse_id' => (int) $row->warehouse_id,
                    'stock_item_id' => (int) $row->stock_item_id,
                    'qty' => 0.0,
                    'avg' => 0.0,
                    'at' => null,
                ];

                $quantity = (float) $row->quantity;
                $after = round($running['qty'] + $quantity, 4);

                // The same arithmetic as post(), deliberately duplicated rather than shared: if the
                // two ever disagree the test that compares them is the thing that tells us, and a
                // shared helper would make both wrong in the same way and the test pass.
                if ($quantity > 0 && $after > 0) {
                    $cost = (float) ($row->unit_cost ?? $running['avg']);
                    $running['avg'] = round(((($running['qty'] * $running['avg']) + ($quantity * $cost)) / $after), 4);
                }

                $running['qty'] = $after;
                $running['at'] = $row->occurred_at;
                $totals[$key] = $running;
            }

            $written = 0;

            foreach ($totals as $running) {
                // DB::table RATHER THAN THE MODEL, and rather than updateOrInsert. The model would
                // apply the tenant scope to a routine whose whole job is to rewrite what the tenant
                // scope filters, and updateOrInsert would stamp `created_at` on rows that already
                // have one — quietly rewriting the day a balance first existed every time anybody
                // rebuilds. The two branches are three lines each and neither lies.
                $key = [
                    'organization_id' => $running['organization_id'],
                    'warehouse_id' => $running['warehouse_id'],
                    'stock_item_id' => $running['stock_item_id'],
                ];

                $figures = [
                    'quantity_on_hand' => $running['qty'],
                    'average_cost' => $running['avg'],
                    'total_value' => round($running['qty'] * $running['avg'], 2),
                    'last_movement_at' => $running['at'],
                    'updated_at' => now(),
                ];

                $existing = DB::table('stock_balances')->where($key)->first();

                if ($existing === null) {
                    DB::table('stock_balances')->insert([...$key, ...$figures, 'created_at' => now()]);
                } else {
                    DB::table('stock_balances')->where('id', $existing->id)->update($figures);
                }

                $written++;
            }

            return $written;
        });
    }
}
