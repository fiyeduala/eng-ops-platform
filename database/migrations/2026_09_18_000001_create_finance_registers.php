<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operational finance registers that complete Phase 5.
 *
 * TAX IS DATA, NOT CODE (§47). A tax code is a company's own row with its own rate, and every
 * expense and income line copies the rate and the recoverability it was raised under. Changing a
 * rate next year must not rewrite this year's figures, so nothing downstream ever reads the live
 * code to work out an old total.
 *
 * ONE CURRENCY PER ORGANIZATION for everything raised here. Foreign currency is a property of what
 * was bought abroad — quotations and purchase orders carry their own rate — and a payment against
 * such an order is made in the order's currency, which is why `payments.currency` exists.
 *
 * PETTY CASH IS A LEDGER WITH A CACHE, the same arrangement as stock balances and asset custody:
 * `petty_cash_accounts.balance` is rebuilt from `petty_cash_transactions`, which are never edited
 * or deleted. A mistake is corrected by a reversal row that points at the one it cancels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->decimal('rate', 7, 4);                          // a percentage: 7.5000 = 7.5 %
            $table->string('applies_to', 20)->default('both');     // sales · purchases · both
            // Whether tax paid on a purchase can be claimed back. Recoverable tax is not a cost to
            // the project; irrecoverable tax is, and the cost ledger has to know which.
            $table->boolean('is_recoverable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('category', 60);
            $table->date('expense_date');

            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee_name', 200)->nullable();
            $table->foreignId('claimant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('receipt_reference', 120)->nullable();

            $table->string('currency', 8);
            $table->decimal('amount', 18, 2);                        // before tax
            $table->foreignId('tax_code_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);          // copied, never re-read
            $table->boolean('tax_recoverable')->default(false);     // copied, never re-read
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('paid_amount', 18, 2)->default(0);      // cache of paid payments

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id', 'expense_date']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'claimant_id']);
        });

        Schema::create('incomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('category', 60);
            $table->date('income_date');
            $table->date('due_on')->nullable();

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_reference', 120)->nullable();

            $table->string('currency', 8);
            $table->decimal('amount', 18, 2);
            $table->foreignId('tax_code_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->decimal('received_amount', 18, 2)->default(0);  // cache of incoming payments

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id', 'due_on']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'client_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('direction', 20);                         // outgoing · incoming

            // What is being settled: a purchase order or an expense going out, an income coming in.
            $table->string('payable_type', 80);
            $table->unsignedBigInteger('payable_id');

            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payee_name', 200)->nullable();

            $table->string('currency', 8);
            $table->decimal('amount', 18, 2);
            $table->string('method', 40);
            $table->string('reference', 120)->nullable();
            $table->date('paid_on')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['payable_type', 'payable_id']);
            $table->index(['organization_id', 'direction', 'status_id']);
        });

        Schema::create('petty_cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 200);
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 8);
            // The imprest: the most the tin is ever topped up to. Zero means no ceiling.
            $table->decimal('float_amount', 18, 2)->default(0);
            $table->decimal('balance', 18, 2)->default(0);          // cache of the ledger below
            $table->boolean('is_active')->default(true);
            $table->string('confidentiality', 20)->default('internal');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'custodian_id']);
        });

        Schema::create('petty_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('petty_cash_account_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('type', 20);                              // replenishment · disbursement · reversal
            $table->decimal('amount', 18, 2);                        // signed: + into the tin, − out of it
            $table->decimal('balance_after', 18, 2);
            $table->date('transacted_on');
            $table->string('description', 500);
            $table->string('category', 60)->nullable();
            $table->string('payee_name', 200)->nullable();
            $table->string('receipt_reference', 120)->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            // A reversal names the row it cancels, and a row can be cancelled once.
            $table->foreignId('reversal_of_id')->nullable()->unique()
                ->constrained('petty_cash_transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'number']);
            $table->index(['petty_cash_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_transactions');
        Schema::dropIfExists('petty_cash_accounts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('incomes');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('tax_codes');
    }
};
