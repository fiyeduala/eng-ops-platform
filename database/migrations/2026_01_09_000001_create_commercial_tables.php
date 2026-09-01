<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outside world: vendors, clients and the contracts that bind us to them (Phase 5).
 *
 * THIS IS THE TABLE PHASE 4 PROMISED. `rfis.party_type`, `submittals.party_type` and
 * `transmittals.to_party_type` have been sitting empty since the engineering registers landed,
 * carrying a typed name in `party_name` and a morph waiting for something to point at. Nothing
 * migrates: existing rows keep the name somebody typed, and new ones point at a real record. That
 * was the whole reason for modelling the morph before the table existed.
 *
 * ONE PARTY TABLE OR TWO? Two. A vendor and a client are the same shape on paper — a name, a tax
 * id, contacts, a bank account — and every system that merges them into a single `parties` table
 * with a `type` column ends up with a prequalification status on rows that were never prequalified
 * and a credit limit on rows that never buy anything. The columns that matter are the ones that
 * differ: a vendor is APPROVED TO BE PAID (prequalification, categories, blacklisting, performance
 * rating), a client is APPROVED TO BE INVOICED (credit terms, billing contact). The handful of
 * organizations that are both get a row in each, which is also how their finance department
 * thinks about them.
 *
 * BANK DETAILS ARE THE MOST DANGEROUS COLUMNS IN THE PLATFORM. Payment redirection fraud works by
 * changing an account number quietly, so `vendors.bank_*` is gated by its own `vendor.bank`
 * permission rather than by `vendor.edit`, every change is written to the audit engine with the
 * before value, and the columns are excluded from search (§61) and from every export that is not
 * explicitly a bank report. See VendorPolicy and VendorService::updateBankDetails().
 *
 * A CONTRACT POINTS BOTH WAYS. `party_type` is `client` or `vendor` and the same table serves both,
 * because the questions asked of a contract — what is it worth, when does it expire, how much
 * retention is held, what has been certified against it — are identical whichever end you are at.
 * What differs is the sign of the money, and that lives in `direction`.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Somebody we buy from.
         *
         * PREQUALIFICATION IS A STATUS, NOT A FLAG. "Approved", "provisional", "suspended",
         * "blacklisted" and "expired" are five different commercial positions with five different
         * consequences at PO time, and a boolean `is_approved` collapses them into a lie. The
         * status graph is configurable like every other register's, so a company that prequalifies
         * in two stages configures two statuses rather than asking for a schema change (§32).
         *
         * `is_blacklisted` IS SEPARATE FROM THE STATUS ON PURPOSE. A blacklisting outlives the
         * status graph — it is a decision about the company, not a position in a process — and the
         * procurement services check it directly. A vendor can be blacklisted from any status, and
         * the reason is mandatory because "why can we not use them" is asked every single time.
         */
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                    // VEN-0001, generated
            $table->string('code', 40)->nullable();          // the buyer's own short code, if they use one
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();
            $table->string('trade_name', 200)->nullable();

            // What they supply. A configurable list (`vendor.categories`), not an enum, because
            // "Civils subcontractor" and "Calibration services" are this company's words.
            $table->string('category', 80)->nullable();
            $table->string('vendor_type', 40)->nullable();   // supplier · subcontractor · consultant · service

            $table->string('registration_no', 80)->nullable();
            $table->string('tax_id', 80)->nullable();

            $table->string('email', 200)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('website', 200)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 120)->nullable();

            // Bank details. Read and written under `vendor.bank`, never under `vendor.view`.
            $table->string('bank_name', 160)->nullable();
            $table->string('bank_account_name', 200)->nullable();
            $table->string('bank_account_number', 60)->nullable();
            $table->string('bank_branch', 160)->nullable();
            $table->string('bank_swift', 40)->nullable();

            // Commercial terms as agreed, in days and percent. Plain numbers in the organization's
            // currency and calendar; nothing here encodes a jurisdiction's payment law (§47).
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable();

            $table->date('prequalified_on')->nullable();
            $table->date('prequalification_expires_on')->nullable();

            // Performance, maintained by the goods-receipt and evaluation services rather than
            // typed by hand. Nullable until there is something to average.
            $table->decimal('rating', 4, 2)->nullable();     // 0.00 – 5.00
            $table->unsignedInteger('rated_count')->default(0);

            $table->boolean('is_blacklisted')->default(false);
            $table->text('blacklist_reason')->nullable();
            $table->timestamp('blacklisted_at')->nullable();
            $table->foreignId('blacklisted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'status_id', 'is_blacklisted']);
            $table->index(['organization_id', 'prequalification_expires_on']);
        });

        /*
         * The people at that vendor.
         *
         * Separate from the vendor row because procurement talks to a person, not to a company, and
         * because that person leaves. `is_primary` is what an RFQ addresses by default; the rest
         * are kept so an old PO still names whoever actually agreed it.
         */
        Schema::create('vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('position', 120)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 60)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'vendor_id', 'is_primary']);
        });

        /*
         * Somebody we sell to.
         *
         * `projects.client_name` has been free text since Phase 2 and stays that way — an existing
         * project keeps the name it was given. `projects.client_id` is added below so new work can
         * point at a real record without rewriting live rows, which is the same promise the party
         * morph made to the engineering registers.
         */
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                    // CLI-0001
            $table->string('code', 40)->nullable();
            $table->string('name', 200);
            $table->string('legal_name', 200)->nullable();

            $table->string('industry', 120)->nullable();
            $table->string('client_type', 40)->nullable();   // configurable: operator · EPC · government · private

            $table->string('registration_no', 80)->nullable();
            $table->string('tax_id', 80)->nullable();

            $table->string('email', 200)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('website', 200)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 120)->nullable();

            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('credit_limit', 18, 2)->nullable();

            $table->foreignId('relationship_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'status_id']);
        });

        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('position', 120)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 60)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'client_id', 'is_primary']);
        });

        /*
         * The agreement itself, in either direction.
         *
         * WHY RETENTION HAS A COLUMN. On a construction contract the money certified is not the
         * money paid: a percentage is held back until practical completion and half of it until the
         * defects period ends. A contract register that cannot say how much is being held cannot
         * answer the single most-asked commercial question on a job, and computing it from a
         * percentage at report time gets it wrong the moment the rate changes mid-contract.
         *
         * VARIATIONS ARE CHANGE REQUESTS, NOT A SECOND TABLE. `change_requests` already models
         * "somebody asked for this, it costs this, who agreed" with an approval route on it, and a
         * contract variation is exactly that seen from the commercial end. `contract_id` on this
         * table's counterpart is added in part B, when a change request can be raised against a
         * contract rather than only against a project.
         *
         * VALUES ARE PLAIN DECIMALS IN THE ORGANIZATION'S CURRENCY, with `currency` recorded for the
         * contracts that genuinely are denominated in another one. There is no exchange rate here:
         * a contract is not a transaction, and the rate that matters is the one on the day money
         * actually moves, which is captured on the payment.
         */
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                    // CTR-2026-0001
            $table->string('title', 300);
            $table->text('description')->nullable();

            // Which end we are at. `client` means money comes in; `vendor` means it goes out.
            $table->string('direction', 20)->default('client');   // client · vendor
            $table->nullableMorphs('party');                      // Client or Vendor
            $table->string('party_name', 200)->nullable();        // as written on the contract

            $table->string('contract_type', 60)->nullable();      // configurable: lump sum · remeasurable · framework · call-off
            $table->string('reference', 120)->nullable();         // THEIR reference, which is what they will quote

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->decimal('value', 18, 2)->nullable();
            $table->string('currency', 8)->nullable();            // null = the organization's own
            $table->decimal('retention_percent', 5, 2)->nullable();
            $table->decimal('retention_held', 18, 2)->default(0);
            $table->decimal('advance_percent', 5, 2)->nullable();

            $table->date('signed_on')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('defects_liability_months')->nullable();

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->text('scope_summary')->nullable();
            $table->text('payment_terms')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('confidential');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'party_type', 'party_id']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'ends_on']);
        });

        /*
         * Point existing records at the new tables, without disturbing what they already hold.
         *
         * Both columns are nullable and neither backfills anything. A project created last month
         * keeps its typed `client_name`; a project created tomorrow can carry both, and the display
         * layer prefers the linked record when there is one. Backfilling by name match would be a
         * guess about which "Rivers Petroleum" was meant, made silently, on live commercial data.
         */
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('client_name')
                ->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->after('contract_reference')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contract_id');
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::dropIfExists('contracts');
        Schema::dropIfExists('client_contacts');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('vendor_contacts');
        Schema::dropIfExists('vendors');
    }
};
