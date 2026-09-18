<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor book — Phase 6, part 6.
 *
 * THIS REGISTER EXISTS TO ANSWER ONE QUESTION UNDER A FIRE ALARM: who is in the building. Everything
 * in the table below is shaped by that. It is why arrival and departure are two separate timestamps
 * and not a single `is_on_site` flag that somebody can forget to clear; why `party_size` is a column
 * rather than an assumption of one head per row; and why `departed` is terminal in the status
 * catalogue, so a record cannot go back on site an hour after it was closed and turn the roll call
 * into a guess.
 *
 * IT IS THE ONE REGISTER IN PHASE 6 THAT IS NOT WORKFLOW-ROUTED, and that is a decision rather than
 * an omission. A receptionist has a person standing at the desk; there is no version of this job in
 * which she waits on somebody's approval inbox before letting them sit down. So there is no
 * `submitted_at`, no `decided_at` and no approval columns here at all — the control is a permission
 * (`visitor.check_in`) held by the people at the desk, not a route through the engine. Where the
 * company genuinely does need a prior decision — a contractor coming to work on live plant — that is
 * a permit, which is a different register with a different answer.
 *
 * WHAT IS RECORDED IS WHAT RECEPTION ACTUALLY ASKS FOR. Name, company, who they are here to see,
 * what for. `phone` and `email` are nullable because most walk-ins will not give them and a required
 * field would simply be filled with rubbish. `id_document_type` and `id_document_number` exist
 * because sites with a gate do check identity documents; the number is encrypted at rest and is not a
 * searchable column, because a visitor book that leaks passport numbers to anybody with the register
 * permission is worse than no visitor book (§67, §68).
 *
 * THE HOST IS AN EMPLOYEE, NOT A STRING. It is what makes the record reachable by the access engine —
 * a visitor belongs to whoever is expecting them, and a supervisor sees their team's visitors through
 * the same scope rules that give them their team's leave. A visit also carries the org unit, the
 * location and optionally the project, which is how a site administrator scoped to one project sees
 * the gate log for that project and nothing else.
 *
 * ESCORT AND INDUCTION ARE COMPANY RULES, NOT SOFTWARE RULES (§47). Whether a contractor needs an
 * escort, and how long a site induction stays valid, are organization settings; this table only
 * records what was decided for this visit and who performed it. `induction_valid_until` is copied
 * onto the visit rather than looked up at read time, because the answer "was this person inducted
 * when they came through the gate" must not change when the rule changes next year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);

            // Who is coming. `full_name` is the only thing reception is guaranteed to get.
            $table->string('full_name', 200);
            $table->string('company_name', 200)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('email', 200)->nullable();

            // A delegation of six arrives as one booking and leaves as one booking. The roll call
            // counts heads, not rows, so the count lives here.
            $table->unsignedSmallInteger('party_size')->default(1);

            // Identity documents. Encrypted at rest, never searched, never shown in a listing.
            $table->string('id_document_type', 40)->nullable();
            $table->text('id_document_number')->nullable();

            // Who they are here to see, and where.
            //
            // TWO COLUMNS FOR ONE PERSON, and it is not an accident. `host_employee_id` is the
            // domain fact and the one that is always available: a delivery is received by a
            // storeman who may well have no sign-in, and a visitor book that could not name him
            // would be missing the host on exactly the visits that most need one. `host_user_id` is
            // what the access engine can actually compare — `own` scope is resolved against the
            // acting user's id — and it is null whenever the host has no account. VisitorService
            // writes both together from the employee, so they cannot drift.
            $table->foreignId('host_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();

            // meeting | delivery | contractor | interview | audit | inspection — company vocabulary.
            $table->string('purpose_type', 40)->nullable();
            $table->string('purpose', 300)->nullable();

            // The booking. Null on a walk-in, which is created at the moment of arrival.
            $table->dateTime('expected_from')->nullable();
            $table->dateTime('expected_to')->nullable();

            // The visit itself. Two timestamps, never one flag.
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();

            // Set by the end-of-day sweep rather than by a human at the desk, because a badge that
            // was never handed back is a different fact from a departure somebody witnessed, and the
            // roll call for last Tuesday should not pretend otherwise.
            $table->boolean('auto_closed')->default(false);

            $table->string('badge_number', 40)->nullable();
            $table->string('vehicle_registration', 40)->nullable();

            // What the site required of them, as decided for THIS visit.
            $table->boolean('requires_escort')->default(false);
            $table->foreignId('escort_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->boolean('induction_completed')->default(false);
            $table->date('induction_completed_on')->nullable();
            $table->date('induction_valid_until')->nullable();

            // Refusals are recorded, with a reason, because being turned away at a gate is exactly
            // the event somebody will ask about afterwards.
            $table->string('refusal_reason', 500)->nullable();
            $table->string('cancellation_reason', 500)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            // The roll call: everybody on site, right now, at one location. This is the index the
            // whole register is for.
            $table->index(['organization_id', 'status_id', 'location_id']);
            // "Who is expected today", which is the screen reception opens at eight in the morning.
            $table->index(['organization_id', 'expected_from']);
            $table->index(['organization_id', 'host_employee_id']);
            $table->index(['organization_id', 'host_user_id']);
            $table->index(['organization_id', 'project_id']);
            // The end-of-day sweep, and the "still on site" audit question.
            $table->index(['organization_id', 'checked_in_at', 'checked_out_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
