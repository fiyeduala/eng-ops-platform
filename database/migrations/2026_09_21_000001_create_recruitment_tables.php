<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recruitment — Phase 6, part 3.
 *
 * A VACANCY IS APPROVED BY THE SAME ENGINE AS EVERYTHING ELSE. Asking for a new head is a spending
 * decision, so the requisition is routed and decided in My Approvals; `vacancies` therefore has no
 * `approved_by` column, only the outcome of a route that lives in `workflow_instances`.
 *
 * APPLICANTS ARE OUTSIDE PEOPLE. An applicant is not an employee and must not be reachable through
 * the personnel register, so they are their own table with their own permission module. The CV is a
 * private file (§67) referenced by path and streamed through an authorized controller — never a
 * public URL, because a CV is somebody's address, date of birth and employment history in one PDF.
 *
 * HIRING CREATES THE EMPLOYEE AND KEEPS THE LINK. `hired_employee_id` is what makes "where did this
 * person come from" answerable a year later, and what stops the same applicant being hired twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 200);

            // The post being filled, where it sits and where it is worked — all optional, because a
            // company may open a vacancy before the post itself has been defined.
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            // Who asked for the head, and who will manage it. The requester is who the route starts
            // from, exactly as on leave and timesheets.
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hiring_manager_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('employment_type', 40)->nullable();   // company data: permanent, contract, …
            $table->unsignedSmallInteger('openings')->default(1);
            $table->unsignedSmallInteger('hired_count')->default(0);
            $table->text('summary')->nullable();
            $table->text('requirements')->nullable();
            $table->date('target_start_date')->nullable();
            $table->date('closes_on')->nullable();

            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'org_unit_id']);
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('vacancy_id')->constrained()->cascadeOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('source', 60)->nullable();            // referral, agency, website — company data
            $table->text('notes')->nullable();

            // The CV. Stored under a generated name on the private disk; the uploaded name is kept
            // here as data rather than as part of a path.
            $table->string('cv_path', 500)->nullable();
            $table->string('cv_name', 255)->nullable();
            $table->string('cv_mime', 120)->nullable();
            $table->unsignedInteger('cv_size')->nullable();

            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('restricted');

            $table->timestamp('offered_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();         // why they were not taken forward
            $table->foreignId('hired_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'vacancy_id', 'status_id']);
        });

        // The interview trail. Rows rather than columns on the applicant, because the number of
        // steps is the company's business and a two-stage process today is a four-stage one in June.
        Schema::create('applicant_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);                          // "Telephone screen", "Panel interview"
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('outcome', 20)->default('pending');    // pending | passed | failed
            $table->unsignedTinyInteger('score')->nullable();     // out of 5, the company's own scale
            $table->text('comments')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'applicant_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_stages');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('vacancies');
    }
};
