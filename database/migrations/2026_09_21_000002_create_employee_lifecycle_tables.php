<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Joining and leaving — Phase 6, part 3.
 *
 * THE STEPS ARE COMPANY DATA, NOT CODE. What a new starter needs on day one is different in a
 * consultancy and on a construction site, and it changes the first time somebody is issued a laptop
 * instead of a desk. So a template is rows an administrator edits, and starting a checklist COPIES
 * those rows onto the person — a template edited in June must not silently rewrite what somebody was
 * asked to do in March.
 *
 * CLEARANCE IS ASKED OF THE SYSTEM, NOT OF THE LEAVER. The blockers on an exit are read from the
 * registers that already know: assets still issued to them, a petty cash tin they still hold. A
 * checklist item is somebody's word that a thing was done; a blocker is the system's own answer, and
 * only the second one survives the person having left.
 *
 * COMPLETING AN EXIT CLOSES THE ACCOUNT. `exited_on` on the personnel record is a fact about
 * employment; the sign-in being switched off is the security consequence, and doing one without the
 * other is how a leaver keeps their access for three weeks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                           // onboarding | exit
            $table->string('name', 160);
            $table->string('description', 500)->nullable();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'kind', 'is_active']);
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->string('description', 500)->nullable();
            // Who is expected to do it — a role rather than a person, because the person changes.
            $table->foreignId('responsible_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('requires_note')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'checklist_template_id', 'sequence']);
        });

        Schema::create('employee_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);                           // onboarding | exit
            // Kept for provenance only; the items below are the copy that counts.
            $table->foreignId('checklist_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->date('started_on');
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'employee_id', 'kind']);
            $table->index(['organization_id', 'kind', 'completed_at']);
        });

        Schema::create('employee_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_checklist_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->string('description', 500)->nullable();
            $table->foreignId('responsible_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('requires_note')->default(false);

            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'employee_checklist_id', 'sequence']);
        });

        Schema::create('employee_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_checklist_id')->nullable()->constrained()->nullOnDelete();

            $table->string('reason', 40);                         // resignation, dismissal, … company data
            $table->date('notified_on')->nullable();
            $table->date('last_working_day');
            $table->text('notes')->nullable();
            // An exit interview is often the only honest feedback a company gets, and it is not for
            // the leaver's line manager to read.
            $table->text('interview_notes')->nullable();
            $table->boolean('eligible_for_rehire')->nullable();

            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('restricted');

            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('access_revoked_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'employee_id']);
            $table->index(['organization_id', 'status_id', 'last_working_day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_exits');
        Schema::dropIfExists('employee_checklist_items');
        Schema::dropIfExists('employee_checklists');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
    }
};
