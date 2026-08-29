<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personnel record (section 4).
 *
 * `supervisor_id` is the configurable reporting line (section 86). It is an arbitrary-depth
 * self-reference — never a hard-coded Employee -> Supervisor -> Manager -> MD chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('employee_no', 64);
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('display_name')->nullable();

            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('work_email')->nullable();
            $table->string('work_phone')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('personal_phone')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();

            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('nationality')->nullable();
            $table->string('national_id')->nullable();
            $table->text('address')->nullable();

            $table->string('employment_type', 32)->default('full_time'); // full_time|part_time|contract|consultant|intern
            $table->string('employment_status', 32)->default('active');  // active|probation|suspended|on_leave|exited
            $table->date('hired_on')->nullable();
            $table->date('probation_ends_on')->nullable();
            $table->date('contract_ends_on')->nullable();
            $table->date('exited_on')->nullable();
            $table->string('exit_reason')->nullable();

            // Private-disk path to the signature image used for internal e-signatures (section 28).
            $table->string('signature_path')->nullable();
            $table->string('photo_path')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'employee_no']);
            $table->index(['organization_id', 'org_unit_id', 'is_active']);
            $table->index(['organization_id', 'supervisor_id']);
            $table->index(['organization_id', 'employment_status']);
            $table->index(['organization_id', 'contract_ends_on']);
            $table->index(['organization_id', 'last_name', 'first_name']);
        });

        // Deferred FK: org_units.head_employee_id could not be constrained at creation time
        // because employees did not exist yet. SQLite cannot add a foreign key to an existing
        // table, so it is skipped there; local dev uses SQLite, production uses MySQL.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('org_units', function (Blueprint $table) {
                $table->foreign('head_employee_id')->references('id')->on('employees')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('org_units', function (Blueprint $table) {
                $table->dropForeign(['head_employee_id']);
            });
        }

        Schema::dropIfExists('employees');
    }
};
