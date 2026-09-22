<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holding things: warehouses, a catalogue, a ledger and the balances derived from it (Phase 5, part 3).
 *
 * THE LEDGER IS THE RECORD AND THE BALANCE IS AN OPINION ABOUT IT. `stock_transactions` is
 * append-only: a movement, once written, is never edited and never deleted, and a mistake is
 * corrected by writing the opposite movement against it (§59). Everything else in this migration
 * can be thrown away and rebuilt by replaying that table in order. That is not a performance
 * decision — it is the only arrangement in which "why does the system say we have nine" has an
 * answer, because the answer is a list of dated rows each naming the person who caused it.
 *
 * WHY `stock_balances` EXISTS AT ALL, GIVEN THE ABOVE. Because a store with four years of movements
 * cannot re-sum the ledger to draw a page, and a site foreman asking whether there are gaskets is
 * asking a question that must be answered in milliseconds. It is a CACHE with a unique key, written
 * inside the same transaction as the movement that changes it, and rebuildable by command. Where
 * the two ever disagree the ledger is right by definition.
 *
 * QUANTITIES ARE SIGNED. A receipt is `+40`, an issue is `-40`, and the balance is a plain SUM over
 * the ledger. The alternative — a positive quantity plus a direction column — makes every balance
 * query a CASE expression and every arithmetic bug silent, because a row with the wrong direction
 * still looks like a sensible row. A signed ledger is how ledgers have worked since well before
 * databases, and the screen renders the sign as a word.
 *
 * ONE VALUATION METHOD: WEIGHTED MOVING AVERAGE. There is deliberately no `valuation_method` column
 * offering FIFO, LIFO and standard costing, because three values in an enum with one implemented
 * behaviour is a lie told to whoever reads the schema next. The average is recalculated on every
 * receipt and carried on the balance; issues leave it alone. If a company needs FIFO it needs
 * layers, and layers are a table this migration does not have — which is a known limit, written
 * down, rather than a column that half means something.
 *
 * NOTHING DERIVED IS STORED, the same rule the procurement migration works to. There is no
 * `quantity_issued` on a material request line: it is the sum of the ledger rows pointing at that
 * line, so a correction posted this afternoon changes what the request says this afternoon. The
 * one apparent exception is `stock_balances`, and it is not an exception — see above.
 *
 * WHAT THIS PART CLOSES. The procurement migration left two columns out because their tables did
 * not exist yet: `purchase_request_items.stock_item_id` and `goods_receipts.warehouse_id`. They are
 * added at the foot of this file, and with them an accepted goods receipt can post stock — which is
 * the middle link of the chain the phase exits on (§90).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A place stock is counted. NOT the same thing as a place on the map.
         *
         * `locations` already knows where the Delta Terminal yard is. A warehouse is a STOCK
         * BOUNDARY, and one yard routinely holds three of them: the main store, the consumables
         * cage the foreman can draw from without paperwork, and the quarantine bay where rejected
         * deliveries sit until the supplier collects them. Modelling those as one location with a
         * type column would mean the rejected valves and the good ones are the same balance.
         *
         * `keeper_id` IS NOT DECORATION. It is the person the segregation-of-duties setting checks
         * against when the same user both buys and receives, and it is who the low-stock notice
         * goes to. A warehouse with no keeper still works; it just tells nobody.
         */
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);                       // WH-MAIN, WH-DT-YARD
            $table->string('name', 200);
            $table->string('type', 30)->default('store');     // store · site · transit · quarantine

            // Where it physically is, and who runs it. Both nullable: a transit store is a lorry.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('keeper_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * ISSUING MORE THAN IS THERE. Refused by default, because a negative balance is nearly
             * always a movement somebody forgot to post rather than a fact about the world. It is a
             * per-warehouse switch and not a global one: a transit store legitimately shows goods
             * leaving before the paperwork that put them there has been entered.
             */
            $table->boolean('allow_negative_stock')->default(false);

            /*
             * A WAREHOUSE HAS A STATUS RATHER THAN AN `is_active` FLAG, because "closed" is not the
             * only way a store stops accepting movements. `suspended` is a store frozen mid-count:
             * physically open, staffed, and refusing every receipt and issue until the stocktake is
             * signed off — which is the one thing a boolean cannot say, and the state in which a
             * ledger most needs to be told to stop.
             */
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'project_id']);
        });

        /*
         * WHAT a thing is, told once, so that every warehouse holding it agrees.
         *
         * The catalogue is the difference between an inventory system and a spreadsheet of
         * deliveries. Procurement lines carry free text — a requester types "12in CS flange, RF,
         * 300#" and a supplier quotes against those words — because insisting on a catalogue code
         * before anybody may ask for anything is how requisition systems get abandoned. Stock is
         * the other way round: you cannot hold a balance of a description. So `stock_item_id` on a
         * requisition line is NULLABLE and offered, and the moment it is filled the two halves of
         * the chain join up.
         *
         * `uom` IS ON THE ITEM AND NOWHERE ELSE. A movement does not get to say it is in metres
         * when the item is counted in coils; that is how a store ends up with 4,000 of something it
         * has four of. Procurement lines still carry their own `uom` because a supplier quotes in
         * whatever they sell in, and reconciling the two is a conversation, not a cast.
         *
         * `is_stock_item` LOOKS REDUNDANT IN A TABLE CALLED stock_items, and is the most useful
         * column here. Sand, diesel and printer paper are bought constantly and counted never; the
         * catalogue is where a company writes down which of the things it buys it intends to track.
         * A non-stock item takes no balance and posts no ledger row — it goes straight to cost.
         */
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);                       // MAT-0001, or the company's own scheme
            $table->string('name', 300);
            $table->text('description')->nullable();
            $table->text('specification')->nullable();

            // Configurable words, not an enum: consumable · spare · tool · material · PPE. What a
            // pipeline contractor categorises by and what a facilities company does are not the
            // same list, and neither is the developer's business (§73).
            $table->string('category', 80)->nullable();
            $table->string('uom', 40);                        // see above: the item owns the unit

            $table->string('manufacturer', 200)->nullable();
            $table->string('part_number', 120)->nullable();
            $table->string('barcode', 120)->nullable();

            $table->boolean('is_stock_item')->default(true);

            /*
             * `discontinued` IS NOT `deleted`, which is why this is a status and not a flag. A line
             * the company has stopped buying still has forty of itself on a shelf, still appears on
             * every historic movement, and still has to be issued until it runs out. It may not be
             * ordered or requested; everything else about it keeps working.
             */
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            /*
             * REORDER LEVELS ARE ADVICE, NOT A CONSTRAINT. Nothing refuses an issue that takes the
             * balance under the level; a notice goes to the keeper and the buyer. Both are nullable
             * because most of a catalogue never earns a considered reorder point, and a default of
             * zero would mean every item is permanently "at level" and the notice means nothing.
             */
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->decimal('reorder_quantity', 18, 4)->nullable();

            // The last price paid, kept for estimating a requisition — NOT a valuation. What the
            // stock is worth lives on the balance, as a weighted average of what was actually paid.
            $table->decimal('last_purchase_price', 18, 4)->nullable();

            // Storage conditions and shelf life, for the items where getting it wrong ruins them.
            $table->string('storage_conditions', 300)->nullable();
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->boolean('is_hazardous')->default(false);

            $table->text('notes')->nullable();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status_id', 'category']);
            $table->index(['organization_id', 'name']);
        });

        /*
         * THE LEDGER. Every other table in this file is downstream of it.
         *
         * APPEND-ONLY, AND MEANT LITERALLY (§59). There is no `updated_at` worth having, no soft
         * delete, and no service method that edits a row. A movement entered wrongly is corrected
         * by a second movement carrying `reverses_transaction_id`, which is why that column is here
         * and why the pair read together tells the truth about what happened — including that
         * somebody got it wrong at 14:32 and fixed it at 14:36. Editing the first row would leave a
         * store whose paper trail and whose database disagree, and the paper trail is the one the
         * auditor brought.
         *
         * SIGNED `quantity`, as the file docblock says: `+` into the warehouse, `-` out of it. The
         * `type` says which KIND of movement it was and never contradicts the sign — the service
         * derives one from the other so no caller can post an `issue` of positive forty.
         *
         * A TRANSFER IS TWO ROWS, not a row with two warehouse columns. Stock leaves one store and
         * arrives at another, and between those two facts it can be on a lorry that has not got
         * there yet. Both rows are written in one database transaction and share a `transfer_ref`,
         * so the pair can be found and neither can exist alone.
         *
         * `source_type`/`source_id` POINT AT A LINE, NOT A HEADER. A goods receipt line, a material
         * request line. That is what makes "how much of this requisition has actually been issued"
         * a sum over this table rather than a number somebody has to remember to update, and it is
         * the join that carries the chain from the delivery through the store to the project cost.
         *
         * `unit_cost` IS CAPTURED AT THE MOVEMENT and never revalued afterwards, for the reason the
         * procurement migration gives about exchange rates: what a thing cost is a fact about the
         * day it arrived. Issues carry the average cost at the moment of issue, which is what makes
         * the value of an issue to a project a defensible figure rather than today's guess.
         */
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                     // STK-2026-000001

            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();

            // receipt · issue · transfer_out · transfer_in · adjustment · return · reversal
            $table->string('type', 30);
            $table->decimal('quantity', 18, 4);               // SIGNED. See above.
            $table->decimal('unit_cost', 18, 4)->nullable();
            $table->decimal('value', 18, 2)->nullable();      // quantity * unit_cost, at this moment

            // The running balance AFTER this row, kept so a ledger page reads like a bank statement
            // without re-summing the table for every line. Written by the service under the same
            // row lock that guards the balance, so it cannot interleave.
            $table->decimal('balance_after', 18, 4)->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // What caused it, at line granularity. Nullable: a stocktake adjustment is caused by a
            // person with a clipboard and points at nothing.
            $table->string('source_type', 60)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->uuid('transfer_ref')->nullable();         // the two halves of one transfer
            $table->foreignId('reverses_transaction_id')->nullable()
                ->constrained('stock_transactions')->nullOnDelete();

            $table->string('batch_ref', 80)->nullable();
            $table->date('expires_on')->nullable();

            // Required by the service for adjustments and reversals, optional elsewhere: moving
            // stock needs no explanation, changing the count without a movement always does.
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('occurred_at');                 // when it happened, not when it was typed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();      // no updated_at: nothing updates

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'warehouse_id', 'stock_item_id', 'id'], 'stock_transactions_org_warehouse_item_id_index');
            $table->index(['organization_id', 'stock_item_id', 'occurred_at'], 'stock_transactions_org_item_occurred_index');
            $table->index(['organization_id', 'source_type', 'source_id']);
            $table->index(['organization_id', 'transfer_ref']);
            $table->index(['organization_id', 'project_id']);
        });

        /*
         * THE CACHE. One row per item per warehouse, and the unique key is the whole point.
         *
         * Every write to this table happens inside the transaction that wrote the ledger row, under
         * `lockForUpdate()` on this row — which is what makes two storekeepers issuing the last
         * four gaskets at the same moment produce one success and one refusal rather than a balance
         * of minus four. The unique index is the second lock: if the code ever tries to create a
         * second row for the same pair, the database refuses instead of quietly splitting the
         * balance in two.
         *
         * `quantity_reserved` IS NOT DERIVED, AND THAT IS DELIBERATE, unlike everything else here.
         * An approved material request holds stock that is still physically present, and a
         * reservation is a promise rather than a movement — there is no ledger row to sum. It is
         * maintained by MaterialRequestService in the same transaction as the approval, and a
         * rebuild recomputes it from the open approved requests rather than from the ledger.
         *
         * `average_cost` IS RECALCULATED ON RECEIPT ONLY. New average = (value on hand + value
         * received) / (quantity on hand + quantity received). Issues take the average out and leave
         * it where it was; that is what "weighted moving average" means and why an issue cannot
         * change what the remaining stock is worth.
         */
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();

            $table->decimal('quantity_on_hand', 18, 4)->default(0);
            $table->decimal('quantity_reserved', 18, 4)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->decimal('total_value', 18, 2)->default(0);

            // For the stocktake report: what was counted, when, and by whom. A store nobody has
            // counted in two years is a fact worth being able to see.
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamp('last_counted_at')->nullable();
            $table->foreignId('last_counted_by')->nullable()->constrained('users')->nullOnDelete();

            // Per-warehouse override of the catalogue level, for the one store where the number is
            // different. Null means "use the item's".
            $table->decimal('reorder_level', 18, 4)->nullable();
            $table->string('bin_location', 60)->nullable();   // aisle/rack/shelf, in the keeper's words

            $table->timestamps();

            $table->unique(['organization_id', 'warehouse_id', 'stock_item_id'], 'stock_balances_unique');
            $table->index(['organization_id', 'stock_item_id']);
        });

        /*
         * The site asking the store for something the company already owns.
         *
         * THIS IS NOT A PURCHASE REQUEST AND MUST NOT BECOME ONE. A purchase request says "buy
         * this"; a material request says "we have this, send it over". They share a shape and
         * nothing else: this one names a warehouse rather than a supplier, is approved by the
         * keeper's chain rather than the buyer's, and terminates in a ledger row rather than an
         * order. Where a store has none of what is asked for, the answer is a purchase request —
         * raised as a new record that names this one, not by mutating this one into it.
         *
         * `quantity_issued` IS ABSENT, per the rule this whole phase works to: it is the sum of the
         * ledger rows whose source is the line. `quantity_approved` IS present, because it is a
         * decision somebody took and not arithmetic — a keeper approving eight of the twelve asked
         * for is the commonest outcome in any store, and the eight has to be recorded somewhere.
         */
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                     // MR-2026-0001
            $table->string('title', 300);
            $table->text('purpose')->nullable();              // what the material is for

            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('priority', 20)->default('normal');
            $table->date('required_by')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_notes')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'warehouse_id']);
            $table->index(['organization_id', 'project_id']);
        });

        /*
         * `stock_item_id` IS REQUIRED HERE, unlike on a purchase request line. You cannot ask a
         * store for a description — the store holds items, and the whole request is a claim about
         * what is on a shelf. `description` is carried alongside it as the words the requester
         * used, because "the blue ones" is what will be said at the counter.
         */
        Schema::create('material_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);

            $table->string('description', 500)->nullable();   // the requester's own words
            $table->decimal('quantity_requested', 18, 4);
            $table->decimal('quantity_approved', 18, 4)->nullable();   // a decision, not arithmetic
            $table->string('uom', 40)->nullable();            // copied from the item, for the record
            $table->date('required_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'material_request_id']);
            $table->index(['organization_id', 'stock_item_id']);
        });

        /*
         * --- The two columns part 2 deliberately left out ------------------------------
         *
         * Both are ALTERs rather than edits to the procurement migration, so that part 2 remains
         * independently reversible and so that a deployment that has already run it does not need
         * the file it ran to change underneath it.
         */

        // A requisition line MAY name a catalogue item. Nullable, and offered rather than required:
        // see the stock_items docblock. This is the join that lets a buyer see that what is being
        // asked for is already sitting in the yard.
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->foreignId('stock_item_id')->nullable()->after('purchase_request_id')
                ->constrained()->nullOnDelete();
            $table->index(['organization_id', 'stock_item_id'], 'pr_items_stock_item_index');
        });

        // WHERE THE DELIVERY WENT. Nullable because a delivery to site that is consumed the same
        // afternoon never enters a store, and forcing a warehouse on it would mean inventing one.
        // When it IS set, accepting the receipt posts the ledger rows — the middle link of §90.
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('location_id')
                ->constrained()->nullOnDelete();
            $table->index(['organization_id', 'warehouse_id'], 'grn_warehouse_index');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropIndex('grn_warehouse_index');
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropIndex('pr_items_stock_item_index');
            $table->dropConstrainedForeignId('stock_item_id');
        });

        Schema::dropIfExists('material_request_items');
        Schema::dropIfExists('material_requests');
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_transactions');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('warehouses');
    }
};
