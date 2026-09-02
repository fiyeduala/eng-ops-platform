<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buying things: request → enquiry → quotation → award → order → delivery (Phase 5, part 2).
 *
 * THIS IS THE PHASE'S EXIT CRITERION, AND IT IS A CHAIN RATHER THAN SIX REGISTERS. §90 is explicit
 * that the platform must not be a collection of unconnected CRUD modules, and procurement is where
 * that is easiest to get wrong: every one of these tables is a perfectly sensible standalone list,
 * and a system that builds them as six lists produces a purchase order nobody can trace to a
 * request and a delivery nobody can trace to an order. So every table below carries the identity of
 * the record it came from, and the services refuse to skip a link that the configuration says
 * exists.
 *
 * EVERY LINK IN THE CHAIN IS NULLABLE, WHICH IS NOT THE SAME AS OPTIONAL. A purchase order may be
 * raised without an RFQ — that is a low-value call-off, and it is how the smaller of the two demo
 * companies buys everything. It may not be raised without a vendor. The difference between "this
 * step is skipped here" and "this step was skipped illegitimately" is a per-organization threshold
 * (`procurement.rfq_required_above`), not a NOT NULL constraint, because the schema cannot know
 * which company is reading it (§73).
 *
 * WHAT IS DELIBERATELY NOT STORED. There is no `quantity_received` on a purchase order line. It is
 * the sum of the receipt lines that point at it, and a cached copy is a number that disagrees with
 * the receipts the first time one is corrected — which, in a goods-in office, is constantly. The
 * same reasoning keeps `is_fully_received` off the header. Both are derived, both are cheap, and
 * neither can be wrong.
 *
 * MONEY. Values are plain decimals in the organization's own currency unless `currency` says
 * otherwise, and `exchange_rate` is captured on the two records where a rate is genuinely fixed by
 * an act — a quotation as submitted, and a purchase order as awarded. Nothing revalues afterwards:
 * a PO placed at last month's rate stays at last month's rate, because that is what was ordered.
 *
 * TAX IS A FIGURE, NOT A RULE (§47). `tax_amount` is what the buyer entered or the vendor quoted.
 * Nothing in this migration knows what VAT, GST or WHT is, what any of them are worth, or which of
 * them applies — that is configuration, and it arrives with the `tax_codes` table in part 5. The
 * column is here now because a quotation total without a tax line is not a quotation.
 *
 * TWO COLUMNS ARE MISSING ON PURPOSE and arrive by ALTER in the next two parts:
 * `purchase_request_items.stock_item_id` and `goods_receipts.warehouse_id` (part 3, inventory), and
 * `tax_code_id` wherever `tax_amount` appears (part 5, finance). Adding them here would mean
 * foreign keys to tables that do not exist yet; adding them later is a three-line migration and
 * keeps each part independently reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Somebody needs something. The entry point of the whole chain.
         *
         * A PURCHASE REQUEST IS NOT AN ORDER AND MUST NOT LOOK LIKE ONE. It carries an ESTIMATE,
         * not a price, and no vendor: the requester is a site engineer who knows they need forty
         * metres of 12" pipe by the fourteenth, and does not know — and should not be choosing —
         * who supplies it. Every field that would let them choose lives further down the chain.
         *
         * SEGREGATION OF DUTIES STARTS HERE. `created_by` requests, `approved_by` approves, and
         * PurchaseRequestService refuses to let them be the same person. That rule is enforced in
         * the service rather than by a CHECK constraint because the exception is legitimate and
         * configurable — a company of eight has one person who does both, and turns the rule off
         * knowingly (`procurement.separate_requester_and_approver`) rather than routing around it.
         */
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                     // PR-2026-0001
            $table->string('title', 300);
            $table->text('justification')->nullable();        // why, in the requester's words

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // Where it is wanted, which is not always where the company is. Null means the
            // organization's default delivery point.
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('priority', 20)->default('normal');    // low · normal · high · urgent
            $table->date('required_by')->nullable();

            // The requester's own guess, totalled from the lines. Never a price — see above.
            $table->decimal('estimated_total', 18, 2)->default(0);
            $table->string('currency', 8)->nullable();

            // The acts, each stamped by the person who performed it. Written by the service after
            // the policy has authorized the act, never by a form (RegisterService::safeAttributes).
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();      // required on rejection, optional on approval

            $table->text('notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'required_by']);
        });

        /*
         * What is actually wanted, line by line.
         *
         * `uom` IS FREE TEXT AND STAYS FREE TEXT. Units of measure are the classic false enum:
         * every list is missing the one somebody needs — "drum (205 L)", "lot", "man-day", "coil" —
         * and a dropdown that cannot express the request gets the nearest wrong answer chosen
         * instead. The inventory catalogue in part 3 supplies a suggested unit when the line is
         * linked to a stock item; the typed value governs, because it is what was asked for.
         */
        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);

            $table->string('description', 500);
            $table->text('specification')->nullable();        // the detail a supplier needs to quote
            $table->decimal('quantity', 18, 4);
            $table->string('uom', 40)->nullable();
            $table->decimal('estimated_unit_price', 18, 4)->nullable();
            $table->decimal('estimated_total', 18, 2)->nullable();

            $table->date('required_by')->nullable();          // a line may be wanted before the rest
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'purchase_request_id']);
        });

        /*
         * The enquiry: the same requirement, put to several suppliers at once.
         *
         * AN RFQ IS OPTIONAL AND OFTEN SKIPPED, LEGITIMATELY. Nobody runs a three-quote exercise to
         * buy a box of gloves, and a platform that forced one would teach its users to raise the
         * order first and paper the enquiry afterwards — which is worse than not having the stage
         * at all. So `purchase_request_id` is nullable in both directions: an RFQ can exist without
         * a request (a market test), and a request can become an order without one.
         *
         * `closes_at` IS A COMMITMENT TO THE SUPPLIERS, not a system deadline. Nothing auto-closes
         * on it — a quotation arriving an hour late is a commercial decision, not a validation
         * error, and a system that silently refused it would simply be lied to about the date.
         */
        Schema::create('rfqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                     // RFQ-2026-0001
            $table->string('title', 300);
            $table->text('scope')->nullable();

            $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->timestamp('closes_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('terms')->nullable();                // delivery, validity, payment expectations
            $table->text('notes')->nullable();

            // The award, recorded on the enquiry rather than on the winning quotation: "which
            // quotation won" and "why the others did not" are one decision, and keeping it here
            // means the losing quotations stay untouched evidence of what was compared.
            //
            // NO FOREIGN KEY, DELIBERATELY. `quotations.rfq_id` already points this way, so a
            // constraint here would be circular — and the only ways out are creating one table in
            // two passes or adding the constraint by ALTER, which SQLite cannot do at all. The
            // development and test databases are SQLite, so an ALTER here would mean the schema
            // differed between what is tested and what ships. The column is indexed, and
            // RfqService is the only thing that writes it.
            $table->unsignedBigInteger('awarded_quotation_id')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('award_reason')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'closes_at']);
        });

        /*
         * The enquiry's own lines, copied from the request when there was one.
         *
         * COPIED, NOT REFERENCED THROUGH. The link back to `purchase_request_item_id` is kept, but
         * the description and quantity are the enquiry's own — because an RFQ line is frequently
         * NOT the request line. Two requests get consolidated into one enquiry, a quantity is
         * rounded to a sensible order size, a specification is rewritten into language a supplier
         * can price. Reading the request's text at display time would mean the enquiry showed
         * something nobody sent.
         */
        Schema::create('rfq_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);

            $table->string('description', 500);
            $table->text('specification')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->string('uom', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'rfq_id']);
        });

        /*
         * Who was asked, and what came back.
         *
         * THE INVITATION IS A RECORD IN ITS OWN RIGHT, not a pivot row with a timestamp. "Who did
         * we ask" is the first question in any procurement audit and the second is "who declined,
         * and did they say why" — a single-supplier award looks very different when the other four
         * were invited and declined than when they were never asked. A plain many-to-many would
         * throw the difference away.
         */
        Schema::create('rfq_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // One invitation per supplier per enquiry. Inviting twice is a mistake, and a second
            // row would double-count the field in any "how many did we ask" report.
            $table->unique(['rfq_id', 'vendor_id']);
            $table->index(['organization_id', 'vendor_id']);
        });

        /*
         * What a supplier said they would charge.
         *
         * A QUOTATION IS EVIDENCE AND IS NEARLY IMMUTABLE. Once it is received it is a statement
         * somebody else made, and editing it silently rewrites what the award was based on. The
         * service allows correction only while the quotation is still being entered, and every
         * change after that is an activity entry against the record (§59).
         *
         * `vendor_id` IS REQUIRED AND `rfq_id` IS NOT. A quotation always comes from somebody; it
         * does not always come from an enquiry. Unsolicited quotations, budget prices collected for
         * an estimate, and the single quote behind a low-value order are all real, and all belong
         * on this register rather than in an email nobody else can find.
         */
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                     // QTN-2026-0001, ours
            $table->string('reference', 120)->nullable();     // THEIRS, which is what they will quote back

            $table->foreignId('rfq_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            $table->date('quoted_on')->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('lead_time_days')->nullable();

            $table->string('currency', 8)->nullable();
            // Fixed when the quotation is entered, so a comparison across currencies is made on one
            // set of numbers. Null means the organization's own currency and needs no rate.
            $table->decimal('exchange_rate', 18, 8)->nullable();

            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);   // a figure, not a rule (§47)
            $table->decimal('total', 18, 2)->default(0);

            $table->text('payment_terms')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->text('notes')->nullable();

            // The supplier's own document — their letterhead quote — held by the document engine so
            // it inherits private storage and the download authorization check (§67).
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            // Quotations are commercially sensitive between opening and award: knowing a rival's
            // number is exactly the leak that makes a tender unsound.
            $table->string('confidentiality', 20)->default('confidential');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'rfq_id']);
            $table->index(['organization_id', 'vendor_id']);
            $table->index(['organization_id', 'valid_until']);
        });

        /*
         * The priced lines.
         *
         * `rfq_item_id` IS NULLABLE BECAUSE SUPPLIERS DO NOT ANSWER THE QUESTION AS ASKED. They
         * offer an alternative, they split one line into two, they add a delivery charge nobody
         * asked about. A schema that insisted every quoted line map to an enquiry line would force
         * the buyer to discard the parts of the offer that did not fit — and the parts that do not
         * fit are usually the commercially interesting ones.
         */
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);

            $table->string('description', 500);
            $table->decimal('quantity', 18, 4);
            $table->string('uom', 40)->nullable();
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->unsignedSmallInteger('lead_time_days')->nullable();
            $table->boolean('is_alternative')->default(false);   // offered instead of what was asked
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'quotation_id']);
        });

        /*
         * The comparison, and the reason one supplier won.
         *
         * THE CRITERIA ARE CONFIGURATION, NOT COLUMNS. A `technical_score` / `commercial_score` /
         * `delivery_score` triple is somebody's procurement policy hard-coded into a schema, and
         * the next company weights safety record, local content or framework compliance instead.
         * So the criteria and their weights come from `procurement.evaluation_criteria` — a
         * per-organization setting — and each score is a row naming the criterion it scored.
         *
         * A ROW PER CRITERION PER QUOTATION PER EVALUATOR. Serious evaluations are done by more
         * than one person and the spread between them is itself evidence; collapsing to one score
         * per quotation would lose the disagreement that a moderation meeting exists to resolve.
         *
         * THE AWARD IS NOT HERE. It lives on the RFQ, because awarding is a decision about the
         * enquiry — which supplier, and why not the others — rather than a property of one score.
         */
        Schema::create('quotation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluated_by')->constrained('users')->cascadeOnDelete();

            // The criterion as configured, stored by BOTH key and label. The key is what the
            // setting used; the label is what it meant at the time. A company that renames a
            // criterion next year must not silently retitle a decision taken this year.
            $table->string('criterion_key', 60);
            $table->string('criterion_label', 120);
            $table->decimal('weight', 6, 2)->default(1);      // as configured when the score was given
            $table->decimal('score', 6, 2);                   // out of `max_score`
            $table->decimal('max_score', 6, 2)->default(10);
            $table->text('comment')->nullable();
            $table->timestamps();

            // One score per criterion per evaluator per quotation. A second is a correction, and a
            // correction updates the row rather than adding a second opinion from the same person.
            $table->unique(['quotation_id', 'evaluated_by', 'criterion_key'], 'quotation_scores_unique');
            $table->index(['organization_id', 'quotation_id']);
        });

        /*
         * The order: the first document in this chain that binds the company to anything.
         *
         * FOUR NULLABLE LINKS BACKWARD, AND THAT IS THE POINT. A purchase order may descend from a
         * quotation (competitive), from a purchase request directly (low value), from a contract
         * (a call-off against a framework), or from none of them (an emergency, raised and
         * justified afterwards). All four happen in real companies and all four are recorded here
         * as what they are, rather than being forced through a shape that only fits the first.
         *
         * THE VENDOR AND THE PRICES ARE COPIED ONTO THE ORDER, not read through the quotation. An
         * order is the company's own statement of what it agreed to pay; reading it through a
         * record the supplier can later have corrected would mean the order changed after it was
         * issued. The link is kept for the trail, and the numbers are ours.
         *
         * `approved_at` AND `issued_at` ARE TWO DIFFERENT EVENTS. Approval is internal — somebody
         * with authority accepted the commitment. Issue is external — the order left the building
         * and the supplier has it. Between them is where a stopped order lives, and a single
         * `sent_at` column loses the distinction entirely.
         */
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                     // PO-2026-0001
            $table->string('title', 300)->nullable();

            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->text('delivery_address')->nullable();     // when it is not one of our locations

            $table->date('ordered_on')->nullable();
            $table->date('required_by')->nullable();

            $table->string('currency', 8)->nullable();
            $table->decimal('exchange_rate', 18, 8)->nullable();   // fixed at award; nothing revalues
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);      // a figure, not a rule (§47)
            $table->decimal('total', 18, 2)->default(0);

            $table->text('payment_terms')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'vendor_id', 'status_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'required_by']);
        });

        /*
         * The ordered lines. What is received is measured against these and only these.
         *
         * NO `quantity_received` COLUMN. See the class docblock: it is the sum of the receipt lines
         * pointing here, and a cached copy is wrong the first time a receipt is corrected.
         */
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_request_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('line_no')->default(1);

            $table->string('description', 500);
            $table->text('specification')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->string('uom', 40)->nullable();
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('line_total', 18, 2)->default(0);
            $table->date('required_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'purchase_order_id']);
        });

        /*
         * It arrived. Possibly not all of it, possibly not in a state anybody wants.
         *
         * A RECEIPT IS AN OBSERVATION, AND OBSERVATIONS ARE NOT AMENDED. What the storeman counted
         * on Tuesday is what they counted on Tuesday; if Wednesday's recount disagrees, Wednesday
         * is a second receipt (or, once part 3 lands, a stock adjustment with a reason). This is
         * the same append-only reasoning the stock ledger is built on, applied one step earlier so
         * the two agree.
         *
         * SEGREGATION OF DUTIES, SECOND HALF. The person who receives goods must not be the person
         * who raised the order — that pairing is how a fictitious delivery gets paid for. Enforced
         * in GoodsReceiptService and, like the requester/approver rule, overridable per organization
         * because a company of eight physically cannot separate them.
         *
         * PARTIAL DELIVERY IS THE NORMAL CASE, not an exception: one order, several receipts, each
         * naming the lines it covered. The header carries no completeness flag for the same reason
         * the order carries no received quantity.
         */
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                     // GRN-2026-0001
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();

            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('delivery_note_ref', 120)->nullable();   // theirs, off the paperwork
            $table->string('carrier', 200)->nullable();
            $table->string('vehicle_ref', 80)->nullable();

            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('inspection_notes')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'purchase_order_id']);
            $table->index(['organization_id', 'received_at']);
        });

        /*
         * Counted, and split three ways.
         *
         * ACCEPTED, REJECTED AND OUTSTANDING ARE THREE DIFFERENT NUMBERS and a single "received"
         * figure hides the one that matters. Twenty delivered, three damaged means seventeen to
         * take into stock, three to raise with the supplier, and three still owed — and a system
         * that stored only "20 received" would put three damaged valves on the shelf.
         *
         * Only `quantity_accepted` becomes a stock movement when part 3 arrives. Rejections stay
         * here, against the line, with the reason.
         */
        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();

            // The order's own line ordering is what a storekeeper reads down the delivery note, so
            // the receipt keeps a line number of its own rather than sorting on an id.
            $table->unsignedInteger('line_no')->default(1);

            $table->decimal('quantity_accepted', 18, 4)->default(0);
            $table->decimal('quantity_rejected', 18, 4)->default(0);
            $table->text('rejection_reason')->nullable();     // required by the service when rejecting
            $table->string('condition', 60)->nullable();      // configurable words: good · damaged · wrong item
            $table->string('batch_ref', 80)->nullable();
            $table->date('expires_on')->nullable();           // consumables with a shelf life
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'goods_receipt_id']);
            $table->index(['organization_id', 'purchase_order_item_id']);
        });

        Schema::table('rfqs', function (Blueprint $table) {
            $table->index(['organization_id', 'awarded_quotation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('quotation_scores');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('rfq_vendors');
        Schema::dropIfExists('rfq_items');
        Schema::dropIfExists('rfqs');
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};
