<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions, transfers and disciplinary cases — Phase 6, part 4.
 *
 * A MOVEMENT IS THE HISTORY, NOT A FORM THAT PRODUCES ONE. The personnel record holds one position
 * and one department — where somebody is NOW — and a system that only ever overwrote those columns
 * could not answer "who approved moving her to Lagos, and when", which is the question that is
 * actually asked, usually a year later and usually by somebody's lawyer. So both sides of every
 * change are stored on the movement: `from_position_id` is written at the moment of application from
 * the record as it then stood, and it is what makes the register readable backwards.
 *
 * A MOVEMENT IS ROUTED, NOT PRESSED. Promoting somebody costs money and transferring them changes
 * who they report to, so a movement goes through the SAME workflow engine as a drawing or a leave
 * request. There is no approve() on the service and no approve route: the engine calls back, and
 * only that callback can mark one approved.
 *
 * APPROVAL AND APPLICATION ARE DIFFERENT DAYS, and the two timestamps are separate because of it. A
 * promotion agreed in September to take effect on 1 January must not move anybody in September, and
 * the personnel record is only rewritten by `apply()` — on or after `effective_on`, once, recorded
 * in `applied_at`. Anything else is a company where people's job titles change when a director
 * clicks Approve.
 *
 * A DISCIPLINARY CASE IS THE MOST SENSITIVE RECORD IN THE PLATFORM. It holds an allegation against a
 * named person, which may be untrue and which the company may end up finding untrue. It is
 * restricted by default, it is never named in a notification (§68), and `summary` is not a
 * searchable column. The one design decision worth spelling out is that an unproven allegation and a
 * proven one share a table: separating them would mean deciding, at the moment of writing, which
 * kind it is — which is the whole point of holding the hearing.
 *
 * WHAT IS NOT HERE IS PAY. A promotion in this register changes the job, not the salary: money
 * belongs to payroll, which is part 5, and putting a salary column here would have created a second
 * place where somebody's pay is recorded and a second answer to what they earn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // promotion | transfer | secondment | acting | regrade — company vocabulary (§47).
            $table->string('kind', 30);

            // Where they were. Written by apply(), read off the personnel record at that moment.
            $table->foreignId('from_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('from_org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('from_supervisor_id')->nullable()->constrained('employees')->nullOnDelete();

            // Where they are going. Any of them may be null: a promotion that leaves somebody in the
            // same department changes only the position, and a null here means "unchanged", not
            // "cleared" — see EmployeeMovementService::apply().
            $table->foreignId('to_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('to_org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_supervisor_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->date('effective_on');
            // Secondments and acting-up arrangements end. A permanent move leaves this null.
            $table->date('ends_on')->nullable();

            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('restricted');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_reason', 500)->nullable();

            // The moment the personnel record was actually rewritten. Separate from decided_at on
            // purpose — see the class docblock.
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'employee_id', 'effective_on']);
            // "What is approved and still waiting to take effect" — the one sweep this table needs.
            $table->index(['organization_id', 'status_id', 'effective_on']);
        });

        Schema::create('disciplinary_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Company vocabulary, both of them. What counts as gross misconduct is a matter for a
            // company's own handbook and its own jurisdiction, and never for this application (§47).
            $table->string('category', 60);                       // conduct | attendance | performance | safety | …
            $table->string('severity', 20)->default('minor');     // minor | serious | gross

            $table->date('incident_on')->nullable();
            $table->date('reported_on');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();

            // The allegation. Restricted, and deliberately absent from registerSearchColumns().
            $table->text('summary');

            $table->foreignId('investigator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('investigation_notes')->nullable();

            $table->date('hearing_on')->nullable();
            $table->text('hearing_notes')->nullable();

            // no_case | informal_advice | verbal_warning | written_warning | final_written_warning |
            // dismissal | other — again the company's own list.
            $table->string('outcome', 40)->nullable();
            $table->text('outcome_notes')->nullable();
            $table->date('decided_on')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            // A WARNING THAT LAPSES. Most disciplinary codes say a written warning stays live for
            // six or twelve months and then stops counting, and a system that cannot express that
            // turns a first offence into a permanent mark.
            $table->date('sanction_expires_on')->nullable();

            $table->date('appeal_received_on')->nullable();
            $table->string('appeal_outcome', 40)->nullable();
            $table->text('appeal_notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('restricted');

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'sanction_expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disciplinary_cases');
        Schema::dropIfExists('employee_movements');
    }
};
