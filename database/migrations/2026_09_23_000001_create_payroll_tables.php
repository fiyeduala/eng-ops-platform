<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll. The part of the platform where §47 stops being a principle and becomes a schema.
 *
 * NO COUNTRY IS NAMED ANYWHERE BELOW, and none may be. Meridian is Nigerian and Kestrel is British;
 * they owe entirely different taxes, to entirely different thresholds, in entirely different
 * currencies, and they are paid by the same code. The way that is possible is that a tax is not a
 * calculation the platform knows — it is a `pay_components` row with a kind, a method, a basis and
 * either a rate or a table of bands. PAYE, National Insurance, pension, NHF, a car loan and a site
 * allowance are all the same kind of object. The engine can compute them without knowing which is
 * which, and a company that owes something none of us have heard of adds a row.
 *
 * A PAYSLIP LINE COPIES EVERYTHING IT WAS CALCULATED UNDER. This is the same discipline `tax_codes`
 * established in Phase 5 for VAT, and it matters more here: a rate change in April must not move a
 * figure on a payslip issued in March. Because the line carries its own code, name, basis and rate,
 * an old payslip can be rebuilt on screen years later without reading a single live configuration
 * row — including after the component it came from has been renamed or deleted outright.
 *
 * PAY IS NOT ON THE EMPLOYEE RECORD, and salary history is not an audit trail bolted onto one.
 * `employee_salaries` is effective-dated: a raise is a NEW ROW, and last March's payslip was
 * calculated on the row that was in force last March. Overwriting a salary column would destroy the
 * only evidence of what somebody was actually owed — which is precisely the question anybody ever
 * asks payroll about a past period.
 *
 * WHAT IS DELIBERATELY NOT HERE:
 *   - No multiplier on `overtime_requests` and no money in the time register. "Time and a half" is a
 *     pay component whose basis is overtime hours, because a company that pays flat rate for
 *     overtime should delete it rather than edit a constant (§47).
 *   - No salary on `employee_movements`. A promotion changes the job; money changes here, and the
 *     two are approved by different people on different days.
 *   - No bank details on `employees`. They live in their own table behind their own permission,
 *     because the blast radius of `employee.view` should never include where somebody's wages go.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The company's pay structure. Every row here is an opinion the company holds and the
         * platform does not.
         *
         * `kind` is what the component does to the payslip:
         *   earning       — adds to gross and to what is paid
         *   deduction     — subtracts from what is paid
         *   employer_cost — costs the company and never touches the employee's net pay
         *   relief        — reduces the TAXABLE basis without being paid or deducted at all
         *
         * `relief` is the one that looks unusual and is not: most tax systems let some part of
         * income escape tax without that money moving anywhere. Without this kind, a company whose
         * tax code grants a personal allowance would have to fake it as a negative earning and would
         * see the wrong gross on every payslip.
         *
         * `method` is how the figure is arrived at:
         *   fixed   — `amount`, flat
         *   percent — `rate` per cent of `basis`
         *   hourly  — `rate` × hours, where the hourly figure comes from the salary row's divisor
         *   banded  — a progressive table in `pay_component_bands`, applied marginally
         *
         * `basis` is what the method is applied TO, and is resolved at the moment the component is
         * reached in `sequence` order: a deduction that reduces taxable income must be sequenced
         * before the tax that reads it, and putting that ordering in the company's hands is the
         * whole point. The platform cannot know whether pension is deducted before or after tax in
         * a jurisdiction it has never heard of.
         */
        Schema::create('pay_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->string('kind', 20);              // earning · deduction · employer_cost · relief
            $table->string('method', 20);            // fixed · percent · hourly · banded
            $table->string('basis', 30)->default('none');
            // none · basic · gross · taxable_gross · pensionable · net · overtime_hours

            $table->decimal('rate', 12, 4)->default(0);       // per cent, or multiplier for hourly
            $table->decimal('amount', 18, 2)->default(0);     // for `fixed`

            // Generic floor and ceiling. A relief expressed as "the greater of X or 1% of gross"
            // needs the floor; a contribution capped at an upper earnings limit needs the ceiling.
            // Neither is country-specific — they are the two shapes every capped levy takes.
            $table->decimal('minimum_amount', 18, 2)->nullable();
            $table->decimal('maximum_amount', 18, 2)->nullable();

            // Which running totals this component feeds. An allowance that is taxed but not
            // pensionable is ordinary everywhere, and the only way to express it is two flags.
            $table->boolean('is_taxable')->default(true);
            $table->boolean('is_pensionable')->default(false);

            // On a DEDUCTION: whether it comes out before tax is worked out. Pension and statutory
            // housing contributions usually do; a car loan repayment usually does not. This is the
            // single most jurisdiction-specific fact in payroll and therefore the last thing that
            // may be decided in code — it is a checkbox, and the company ticks it.
            $table->boolean('reduces_taxable')->default(false);

            // Whether a part-period gets a part of it. A starter halfway through the month earns
            // half a housing allowance but usually still repays a whole loan instalment, and which
            // of those a given component is cannot be inferred from its kind.
            $table->boolean('is_prorated')->default(true);

            // Automatic for everyone, or only for the people it is explicitly given to. A pension
            // scheme is usually the former; a car loan repayment is always the latter.
            $table->boolean('applies_to_all')->default(false);

            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'is_active', 'sequence']);
        });

        /*
         * A progressive table. Marginal by definition: each band taxes only the slice of the basis
         * that falls inside it, which is how every progressive system in the world works and is not
         * a property of any one of them.
         *
         * `to_amount` null means "and everything above", so the top band needs no special casing and
         * a table cannot silently stop taxing at its highest figure.
         */
        Schema::create('pay_component_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->decimal('from_amount', 18, 2)->default(0);
            $table->decimal('to_amount', 18, 2)->nullable();     // null = no upper limit
            $table->decimal('rate', 12, 4)->default(0);          // per cent within this band
            $table->timestamps();

            $table->index(['organization_id', 'pay_component_id', 'sequence'], 'pay_component_bands_org_component_seq_index');
        });

        /*
         * What somebody is paid, from when. Never updated in place.
         *
         * `hours_per_period` is the divisor that turns a periodic salary into an hourly figure for
         * overtime. It is here rather than in code because it is a number companies genuinely
         * disagree about — a contracted weekly figure in one country, a conventional monthly
         * constant in another — and §47 puts that disagreement in the database.
         */
        Schema::create('employee_salaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();            // null = still in force

            $table->decimal('basic_amount', 18, 2);
            $table->string('currency', 3);
            $table->string('frequency', 20)->default('monthly'); // monthly · weekly · daily · hourly · annual
            $table->decimal('hours_per_period', 8, 2)->nullable();

            $table->string('reason', 200)->nullable();
            $table->text('notes')->nullable();

            // Salary is not ordinary employee data. The register is restricted by default and the
            // person's own line manager does not read it without being given payroll confidentiality.
            $table->string('confidentiality', 20)->default('restricted');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'employee_id', 'effective_from'], 'employee_salaries_org_employee_effective_index');
        });

        /*
         * What THIS person gets that the structure does not give everybody — a site allowance while
         * they are on the job, a loan repayment until it is repaid. Effective-dated for the same
         * reason salaries are: a deduction that ended in June must not reappear when somebody
         * reruns June.
         */
        Schema::create('employee_pay_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_component_id')->constrained()->cascadeOnDelete();

            // Overrides. Null means "use the component's own figure", which is the common case —
            // the row then says only that this person has this component at all.
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('rate', 12, 4)->nullable();

            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('reference', 120)->nullable();        // e.g. the loan agreement number
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'employee_id', 'is_active'], 'employee_pay_components_org_employee_active_index');
        });

        /*
         * Where the money goes. Its own table because it needs its own permission (§67): the blast
         * radius of `employee.view` must not include somebody's account number.
         *
         * The account and sort code are encrypted at rest. That makes them unsearchable, which is
         * the correct trade — nobody has a legitimate reason to search the workforce BY account
         * number, and a database dump should not be a list of everyone's banking details.
         */
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->string('bank_name', 160);
            $table->string('account_name', 200);
            $table->text('account_number');                      // encrypted
            $table->text('branch_code')->nullable();             // encrypted; sort code, routing, IBAN
            $table->string('account_type', 40)->nullable();

            // The last few digits, in clear, for confirming a payment without revealing the number.
            // Storing it separately is deliberate: the alternative is decrypting every row to render
            // a list, which turns a screen into a bulk decryption of the entire workforce.
            $table->string('masked_tail', 8)->nullable();

            $table->boolean('is_primary')->default(true);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'employee_id', 'is_active'], 'employee_bank_accounts_org_employee_active_index');
        });

        /*
         * A run: one period, one frequency, one currency, many payslips.
         *
         * `locked_at` is what approval sets, and after it nothing in the run may be edited. A
         * mistake found afterwards is not corrected by editing — it is corrected by a NEW run that
         * points back at this one through `corrects_payroll_run_id`, leaving both on the record.
         * That is §59 applied to money: the wrong figure and the correction are both evidence, and a
         * payroll history that can be tidied afterwards is worth nothing to an auditor.
         *
         * The totals are a cache, rebuilt from the payslips. They exist so a register listing twenty
         * runs does not sum a hundred thousand lines to draw itself (§89).
         */
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('name', 200);

            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date')->nullable();
            $table->string('frequency', 20)->default('monthly');
            $table->string('currency', 3);

            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('restricted');

            $table->decimal('gross_total', 18, 2)->default(0);
            $table->decimal('deduction_total', 18, 2)->default(0);
            $table->decimal('net_total', 18, 2)->default(0);
            $table->decimal('employer_cost_total', 18, 2)->default(0);
            $table->unsignedInteger('payslip_count')->default(0);

            $table->foreignId('corrects_payroll_run_id')->nullable()
                ->constrained('payroll_runs')->nullOnDelete();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('notes')->nullable();

            // A period opened in error, or superseded before anybody was paid. Cancelling keeps the
            // run and its number on the register rather than deleting it: a gap in the payroll
            // numbering is a question somebody will have to answer later, and "cancelled, because"
            // is a much better answer than a missing row.
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'period_start', 'period_end']);
            $table->index(['organization_id', 'status_id']);
        });

        /*
         * One person, one period. `period_fraction` is how much of the period they were employed
         * for — 1 for everybody who was there throughout, and less for a starter or a leaver.
         *
         * `skipped_reason` is the answer to the worst failure mode payroll has. Somebody with no
         * salary row must NEVER be quietly paid zero and must never be silently dropped from the
         * run either: they get a payslip that pays nothing and says why, so the omission is visible
         * on the register instead of being discovered by the person when their wages do not arrive.
         *
         * `user_id` IS DELIBERATELY DUPLICATED FROM THE EMPLOYEE, and it is not denormalization for
         * speed. `AccessScope::Own` resolves against columns on the record's OWN table and cannot
         * reach through `accessParentRelation` to the employee — by design, and the same reason
         * `performance_reviews` carries a `user_id` of its own. Without this column "my payslips"
         * returns an empty list to every ordinary employee in the company while looking, from the
         * outside, exactly like a working feature.
         *
         * It is nullable because not every employee has a login: a site labourer paid through the
         * same run may have no account at all, and their payslip is reached by HR rather than by
         * them. Null here means "nobody owns this row personally", which is the correct answer.
         */
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 64);

            $table->string('currency', 3);
            $table->decimal('basic_amount', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->default(0);
            $table->decimal('taxable_amount', 18, 2)->default(0);
            $table->decimal('pensionable_amount', 18, 2)->default(0);
            $table->decimal('relief_amount', 18, 2)->default(0);
            $table->decimal('deduction_amount', 18, 2)->default(0);
            $table->decimal('net_amount', 18, 2)->default(0);
            $table->decimal('employer_cost_amount', 18, 2)->default(0);

            $table->decimal('period_fraction', 8, 6)->default(1);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('unpaid_leave_days', 8, 2)->default(0);

            $table->string('skipped_reason', 300)->nullable();
            $table->text('notes')->nullable();
            $table->string('confidentiality', 20)->default('restricted');

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['organization_id', 'employee_id']);
            // "My payslips" is the most-run query in the module — every employee, every month.
            $table->index(['organization_id', 'user_id']);
        });

        /*
         * The frozen detail. Everything needed to explain the figure is COPIED here, so a payslip
         * from three years ago renders correctly against a pay structure that has since been
         * rewritten — and still renders after the component itself has been deleted, which is why
         * `pay_component_id` may be null while `code` and `name` never are.
         *
         * `basis_amount` is what the rate was applied to. Without it a payslip shows a number and no
         * working, and the first question anybody asks about a deduction is what it was taken from.
         */
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_component_id')->nullable()->constrained()->nullOnDelete();

            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('kind', 20);
            $table->string('method', 20);
            $table->string('basis', 30);
            $table->boolean('is_prorated')->default(true);

            $table->decimal('rate', 12, 4)->default(0);
            $table->decimal('quantity', 10, 2)->nullable();      // hours, for `hourly`
            $table->decimal('basis_amount', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);

            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'payslip_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employee_pay_components');
        Schema::dropIfExists('employee_salaries');
        Schema::dropIfExists('pay_component_bands');
        Schema::dropIfExists('pay_components');
    }
};
