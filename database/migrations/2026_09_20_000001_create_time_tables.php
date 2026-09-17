<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance, timesheets and overtime — Phase 6, part 2.
 *
 * TIMESHEETS AND OVERTIME ARE ROUTED BY THE SAME ENGINE AS A DOCUMENT, exactly as leave is. Neither
 * table has an `approved_by`: the decision lives in `workflow_instances` and `approvals`, and these
 * rows record only the outcome.
 *
 * HOURS, NEVER MONEY. Nothing here knows a rate. Approved hours are what payroll (part 5) reads, and
 * pricing them belongs there, restricted, rather than on a row every line manager can open.
 *
 * ATTENDANCE IS NOT APPROVED. A clock-in is a fact about when somebody arrived. It can be corrected
 * by somebody holding `attendance.edit`, with a reason that is kept, but it does not wait in anybody's
 * inbox — the thing that is approved is the timesheet that claims the hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // The employee's sign-in, copied so `own` scope can be answered from this row.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->date('work_date');
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->unsignedInteger('minutes')->nullable();        // worked out on the server

            // Only captured when the company switches it on (`attendance.capture_location`), and
            // then only if the browser offers it. Never required: a basement plant room has no fix.
            $table->decimal('clock_in_latitude', 10, 7)->nullable();
            $table->decimal('clock_in_longitude', 10, 7)->nullable();
            $table->decimal('clock_out_latitude', 10, 7)->nullable();
            $table->decimal('clock_out_longitude', 10, 7)->nullable();

            $table->string('source', 20)->default('self');         // self | manual
            $table->string('notes', 500)->nullable();

            // A correction keeps who, when and why. The previous times are in the audit log.
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('adjusted_at')->nullable();
            $table->string('adjustment_reason', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'employee_id', 'work_date']);
            $table->index(['organization_id', 'work_date']);
        });

        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('period_start');                          // the first day of the company week
            $table->date('period_end');
            $table->decimal('total_hours', 6, 2)->default(0);      // summed on the server
            $table->text('notes')->nullable();

            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'employee_id', 'period_start']);
            $table->index(['organization_id', 'status_id', 'period_start']);
        });

        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->date('work_date');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('hours', 5, 2);
            $table->unsignedSmallInteger('line')->default(0);      // which row of the weekly grid
            $table->timestamps();

            $table->index(['organization_id', 'timesheet_id']);
            $table->index(['organization_id', 'project_id', 'work_date']);
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('work_date');
            $table->decimal('hours', 5, 2);                        // asked for, before the work
            $table->decimal('worked_hours', 5, 2)->nullable();     // recorded after, never above `hours`
            $table->text('reason')->nullable();

            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained()->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'employee_id', 'work_date']);
            $table->index(['organization_id', 'status_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('timesheets');
        Schema::dropIfExists('attendance_records');
    }
};
