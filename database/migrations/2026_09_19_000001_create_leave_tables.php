<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave — the first people register, and the one Phase 6's exit criterion is written against.
 *
 * A LEAVE REQUEST IS ROUTED BY THE SAME ENGINE AS A DOCUMENT. There is no `approved_by` column
 * here and no approval table of its own: the decision lives in `workflow_instances` and
 * `approvals`, exactly where a drawing's does, and the request only records the outcome. A second
 * approval path for HR would be the divergence §78 names as the failure to design against.
 *
 * LEAVE TYPES AND PUBLIC HOLIDAYS ARE COMPANY DATA (§47). "Annual 21 days", "Sick 10 days" and
 * "1 October" are rows an HR manager typed, not constants — no country's entitlement rules are
 * written into the application.
 *
 * A BALANCE STORES WHAT WAS GRANTED, NEVER WHAT WAS USED. `entitled`, `carried_over` and
 * `adjustment` are decisions somebody made; the days taken are computed from the requests
 * themselves every time. A `used` column would be a cache that drifts the first time a request is
 * cancelled on a path that forgot to decrement it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Null means the type is not counted against a balance at all — unpaid or
            // compassionate leave in most companies is recorded and approved, not rationed.
            $table->decimal('days_per_year', 6, 2)->nullable();
            $table->decimal('carry_over_limit', 6, 2)->default(0);
            $table->unsignedSmallInteger('max_consecutive_days')->nullable();
            $table->unsignedSmallInteger('min_notice_days')->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->boolean('allow_half_days')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'code']);
        });

        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->date('holiday_date');
            // Same day and month every year. Holidays that move — lunar calendars, "the first
            // Monday in May" — are entered per year, because computing them is country rules.
            $table->boolean('is_recurring')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'holiday_date']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled', 6, 2)->default(0);
            $table->decimal('carried_over', 6, 2)->default(0);
            $table->decimal('adjustment', 6, 2)->default(0);        // may be negative
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'employee_id', 'leave_type_id', 'year'], 'leave_balances_unique');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            // The employee's sign-in, copied so `own` scope and the workflow's "record owner" can be
            // answered from this row. HR may type a request in for somebody; it is still theirs.
            $table->foreignId('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('start_half_day')->default(false);     // starts after lunch
            $table->boolean('end_half_day')->default(false);       // back after lunch
            $table->decimal('days', 6, 2)->default(0);             // worked out on the server
            $table->text('reason')->nullable();
            $table->foreignId('handover_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('contact_while_away', 200)->nullable();

            // The unit is copied from the employee when the request is made, so a department head
            // sees the leave of people who were in the department when it was taken.
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
            $table->index(['organization_id', 'employee_id', 'start_date']);
            $table->index(['organization_id', 'status_id', 'start_date']);
            $table->index(['organization_id', 'org_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('leave_types');
    }
};
