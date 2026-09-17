<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The things the company owns and the things it drives (Phase 5, part 4).
 *
 * AN ASSET IS NOT A STOCK ITEM, and the difference is the whole reason this migration exists rather
 * than an `is_serialised` flag on `stock_items`. Stock is FUNGIBLE: forty metres of the same cable
 * is one balance and nobody cares which forty. An asset is SINGULAR — generator SN-4471 is in
 * Warri, it is Musa's responsibility, it was serviced in March and its insurance lapses in
 * November. The questions are not the same shape. Stock asks "how many"; an asset asks "which one,
 * where, whose, and what has happened to it". A quantity column cannot answer the second list.
 *
 * CUSTODY IS A LEDGER, NOT A COLUMN — the same argument `stock_transactions` makes, for the same
 * reason. `assets.custodian_id` is a CACHE of the newest row in `asset_custody_transfers`, written
 * in the same transaction as the transfer. The trail is append-only and enforced in the model,
 * because "who had the laptop in June" is a question asked precisely when somebody wants the answer
 * changed (§59). Overwriting the column and keeping no history is how a missing generator becomes
 * nobody's fault.
 *
 * CUSTODY IS FOUR-WAY AND ALL FOUR ARE NULLABLE. A thing can be held by a person, by a department,
 * by a project, or simply be at a place. A tower crane is the Delta Terminal project's and no
 * single person's; a laptop is Chidi's; a fire extinguisher belongs to the floor it hangs on.
 * Forcing a person onto every asset produces the fiction every asset register drifts into —
 * everything assigned to the storekeeper, which means nothing is assigned to anyone.
 *
 * A VEHICLE IS AN ASSET WITH AN ENGINE, so `vehicles` is one-to-one with `assets` rather than a
 * parallel register. A pickup is bought, capitalised, transferred, maintained and retired exactly
 * like a generator — everything in `assets` applies unchanged — and it additionally has a plate, an
 * odometer and a fuel habit. Duplicating the asset columns into a fleet table would mean custody
 * history works for generators and not for trucks, which is the same bug twice.
 *
 * CONSUMPTION IS NEVER STORED. `fuel_logs` records litres and an odometer reading, and litres per
 * 100 km is arithmetic over the PREVIOUS log for that vehicle. Storing it would mean a back-dated
 * entry — which happens constantly, because receipts arrive in batches on Fridays — leaves a column
 * that disagrees with the rows it was computed from, and no one can tell which is wrong. The same
 * rule the procurement and inventory migrations work to.
 *
 * MAINTENANCE IS ONE TABLE, PLANNED AND UNPLANNED. Not two, because the difference is a flag and a
 * date, and every question worth asking — what has this cost, how long was it down, who did the
 * work — is asked across both. A breakdown is a maintenance record whose plan never existed; a
 * service is one whose plan did. Splitting them means every report is a UNION.
 *
 * NO DEPRECIATION. Deliberately. It is a known limit written down rather than a
 * `depreciation_method` enum with one implemented branch, which is a lie told to the next person
 * who reads the schema. `purchase_cost` is what was paid, and what it is worth today is a question
 * this platform does not yet answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The register of singular things.
         *
         * `goods_receipt_item_id` IS THE POINT OF THE PHASE (§90). A generator does not appear in an
         * asset register by typing; it arrives on a delivery, against an order, against an approved
         * requisition. Where the receipt line is known the whole trail back to the request is a join
         * away, and "why do we own this" has a documented answer. It is nullable because every
         * organization starts with assets it already had on the day it started using this, and
         * refusing to record those would make the register useless in week one.
         *
         * `is_vehicle` IS A DISCRIMINATOR, NOT A CATEGORY. It says a `vehicles` row exists, so the
         * register can render fleet columns without a join per row and the navigation can offer a
         * fleet view. The category — plant, IT equipment, furniture — is a configurable word beside
         * it, because what a drilling contractor and a facilities company categorise by are not the
         * same list and neither is the developer's business (§73).
         */
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 60);                        // AST-0001, or the company's scheme
            $table->string('name', 300);
            $table->text('description')->nullable();

            $table->string('category', 80)->nullable();         // plant · vehicle · IT · furniture
            $table->string('manufacturer', 200)->nullable();
            $table->string('model', 200)->nullable();
            $table->string('serial_number', 120)->nullable();   // the manufacturer's identity
            $table->string('tag_number', 120)->nullable();      // the company's own sticker

            /*
             * WHOSE IT IS, RIGHT NOW. A cache of the newest `asset_custody_transfers` row — see the
             * file docblock. Nothing writes these four columns except AssetService::transferCustody(),
             * and the trail can rebuild them at any time.
             */
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            /*
             * How it came to be owned. `vendor_id` is who supplied it — kept even when the receipt
             * line is known, because a warranty claim is made against the supplier and the join
             * through four tables to find them is exactly the join nobody makes at 7am.
             */
            $table->date('acquired_on')->nullable();
            $table->decimal('purchase_cost', 18, 2)->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_item_id')->nullable()
                ->constrained('goods_receipt_items')->nullOnDelete();

            // Warranty and the end of life. `disposed_on` is set when the status says retired; the
            // row is never deleted, because a disposed asset is most of what an auditor asks about.
            $table->date('warranty_expires_on')->nullable();
            $table->date('disposed_on')->nullable();
            $table->string('disposal_reference', 120)->nullable();

            /*
             * in service · under maintenance · idle · retired · lost. A status, not a boolean pair,
             * because "is it working" and "is it here" are different questions, and a company that
             * tracks stolen plant separately from written-off plant is not doing anything unusual.
             */
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            /*
             * A VEHICLE ROW EXISTS FOR THIS ASSET. Set by the service, never by hand — see the file
             * docblock on why the fleet is not a separate register.
             */
            $table->boolean('is_vehicle')->default(false);

            $table->text('notes')->nullable();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'status_id', 'category']);
            $table->index(['organization_id', 'custodian_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'is_vehicle']);
            $table->index(['organization_id', 'serial_number']);
        });

        /*
         * WHO HAD IT, WHEN, AND WHO SAID SO. Append-only.
         *
         * Both ends are recorded — `from_*` as well as `to_*` — even though the previous row could
         * supply them. Not redundancy: the row is the DOCUMENT of a handover and has to stand alone
         * in a dispute, and the first row of an asset's life has a `to` and no `from` at all. Only
         * the four `to_*` columns feed the cache on `assets`.
         *
         * `authorised_by` IS NOT `created_by`. A storekeeper types the handover; a manager permits
         * it. Where the organization does not separate the two they are the same person and nothing
         * is lost; where it does, the distinction is the entire control.
         */
        Schema::create('asset_custody_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('from_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->foreignId('to_custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('to_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->timestamp('transferred_at');
            $table->string('reason', 300)->nullable();

            /*
             * WHAT STATE IT WAS HANDED OVER IN. The one field that decides who pays for the crack in
             * the casing, which is why it belongs on the handover and not on the asset.
             */
            $table->string('condition', 40)->nullable();       // good · fair · poor · damaged
            $table->text('remarks')->nullable();

            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // No soft deletes. A handover is not withdrawn; a wrong one is corrected by another.
            $table->index(['organization_id', 'asset_id', 'transferred_at']);
            $table->index(['organization_id', 'to_custodian_id']);
        });

        /*
         * Work done to an asset, planned or not.
         *
         * `due_on` AND `due_odometer` ARE BOTH NULLABLE AND BOTH MEAN THE SAME THING in different
         * units. A generator is serviced every 250 running hours, a pickup every 5,000 km, an air
         * conditioner every six months. One column cannot say all three, and an organization that
         * tracks a truck by distance and a crane by date is not doing anything exotic. Either, both
         * or neither may be set; whichever arrives first is what makes the work due.
         *
         * `downtime_hours` IS RECORDED, NOT DERIVED, and that is a deliberate exception to the rule
         * this schema otherwise keeps. `completed_at - started_at` is elapsed time, which counts the
         * weekend the workshop was shut. Availability is a number people are held to, so it is
         * entered by the person who knows rather than computed from timestamps that do not.
         */
        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 60);                   // MNT-0001
            $table->string('title', 300);
            $table->text('description')->nullable();

            /*
             * PLANNED VS UNPLANNED IS THE WHOLE ANALYSIS. The ratio between them is what a
             * maintenance department is judged on, so it is a first-class column rather than a
             * category word — this one the developer DOES get to define, because it classifies not
             * the work but whether anybody saw it coming.
             */
            $table->boolean('is_planned')->default(true);
            $table->string('maintenance_type', 40)->nullable(); // service · repair · inspection · overhaul

            $table->date('due_on')->nullable();
            $table->unsignedBigInteger('due_odometer')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->decimal('downtime_hours', 10, 2)->nullable();
            $table->unsignedBigInteger('odometer')->nullable(); // reading when the work was done

            /*
             * WHO DID IT — one of two, rarely both. In-house means a person; outsourced means a
             * vendor. Both nullable, because a planned job that has not happened yet has neither.
             */
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();

            /*
             * WHAT IT COST, AND NOT YET WHERE IT LANDS. Part E gives this a `project_costs` posting;
             * until then the figure is recorded and reported on but posts to nothing, which is
             * honest about the state of the build rather than a half-wired foreign key.
             */
            $table->decimal('labour_cost', 18, 2)->nullable();
            $table->decimal('parts_cost', 18, 2)->nullable();
            $table->string('work_order_reference', 120)->nullable();
            $table->text('findings')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'reference']);
            $table->index(['organization_id', 'asset_id', 'due_on']);
            $table->index(['organization_id', 'status_id', 'is_planned']);
        });

        /*
         * The engine half of a vehicle. See the file docblock for why this is not its own register.
         *
         * `current_odometer` IS A CACHE OF THE NEWEST READING, the third cache in this migration
         * built the same way as the other two. It exists so a register page can show a fleet without
         * a subquery per row; the fleet service writes it, and it is rebuildable from `fuel_logs`.
         */
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('registration_number', 40);          // the plate
            $table->string('vin', 60)->nullable();
            $table->unsignedSmallInteger('year_of_manufacture')->nullable();
            $table->string('colour', 40)->nullable();

            $table->string('vehicle_type', 40)->nullable();     // pickup · truck · bus · plant
            $table->string('fuel_type', 30)->nullable();        // diesel · petrol · electric
            $table->decimal('tank_capacity', 10, 2)->nullable();

            $table->unsignedBigInteger('current_odometer')->nullable();
            $table->string('odometer_unit', 10)->default('km'); // km · mi · hours, for plant

            $table->foreignId('assigned_driver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'registration_number']);
            $table->index(['organization_id', 'assigned_driver_id']);
        });

        /*
         * Every fill, and nothing computed from them.
         *
         * THE ODOMETER MUST NOT GO BACKWARDS, and that is a service guard rather than a database
         * constraint, because the correct response to a backwards reading is a sentence naming the
         * reading being questioned — not a violated index that names a column. See FleetService.
         * Consumption is derived from the previous log; see the file docblock.
         *
         * `total_cost` IS STORED even though litres times unit cost would give it, because a fuel
         * receipt frequently is not the product of the two — a rounded pump total, a discount, a
         * levy. What was actually paid is the primary fact and the unit cost is the derived one.
         */
        Schema::create('fuel_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->timestamp('filled_at');
            $table->decimal('litres', 12, 3);
            /*
             * NULLABLE, because `fuel_log.odometer_required` is a per-company setting and a small
             * firm with two vans genuinely wants the fuel spend and nothing else. The consequence is
             * carried honestly by the arithmetic rather than papered over with a zero: a log with no
             * reading is excluded from consumption and reports no distance, because a fill at an
             * unknown odometer tells you nothing about how far the vehicle went.
             */
            $table->unsignedBigInteger('odometer')->nullable();
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->decimal('total_cost', 18, 2)->nullable();

            // A partial fill cannot be used to compute consumption — you do not know what went in
            // before the last one. The flag is what lets the arithmetic decline to answer honestly.
            $table->boolean('is_full_tank')->default(true);

            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('station', 200)->nullable();
            $table->string('receipt_reference', 120)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'vehicle_id', 'odometer']);
            $table->index(['organization_id', 'filled_at']);
        });

        /*
         * The papers that make a vehicle legal, and the dates they stop doing so.
         *
         * EXPIRY IS A DATE QUERY, NOT A STATUS, which is the arrangement `contracts`, `vendors`,
         * `training_records` and `risk_assessments` already use and the reason none of them needed a
         * nightly job to mark things expired. A register tab asks `expiringWithin($days)`, the
         * warning window is a per-organization setting, and a document that lapsed at midnight is
         * expired at 00:00:01 without anything having run.
         *
         * `file_path` IS PRIVATE STORAGE (§67). An insurance certificate carries a policy number and
         * a signature; it is served through a controller that checks the policy first, never from a
         * public disk.
         */
        Schema::create('vehicle_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            /*
             * A CONFIGURABLE WORD, NOT AN ENUM (§47, §73). Insurance, roadworthiness, a hackney
             * permit, a state road tax, a bridge pass — the list differs by country and by what the
             * vehicle does, and hard-coding it would bake one jurisdiction's paperwork into the
             * schema. The seeds propose a list; an organization changes it without a migration.
             */
            $table->string('document_type', 80);
            $table->string('document_number', 120)->nullable();
            $table->string('issuer', 200)->nullable();

            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('cost', 18, 2)->nullable();

            $table->string('file_path', 500)->nullable();       // private disk — see above
            $table->string('file_name', 300)->nullable();
            $table->string('file_mime', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->text('notes')->nullable();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'vehicle_id', 'expires_on']);
            $table->index(['organization_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('fuel_logs');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('asset_maintenances');
        Schema::dropIfExists('asset_custody_transfers');
        Schema::dropIfExists('assets');
    }
};
