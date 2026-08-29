<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects and the two tables that decide who can see them (§13, §14).
 *
 * `project_members` is the single source of truth for the project visibility axis
 * (docs/05-project-visibility.md §5). ActorProfile reads it directly — user_id, is_active,
 * starts_on, ends_on — so those four columns are load-bearing for authorization, not bookkeeping.
 * Renaming one silently narrows or widens what every member of every project can see, which is
 * why they are named here exactly as the resolver expects them.
 *
 * `project_departments` is the cascade in §13: a project is assigned to DEPARTMENTS, and each
 * department's head then assigns their own people. It grants no visibility by itself — a head
 * sees the project because their department is on it, and the engineers see it because they were
 * made members. Conflating the two would give every member of a department sight of every project
 * their department touches, which is precisely the leak §14 exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Configurable catalogue: what kinds of work this company does. Seeded with a starter set
        // by OrganizationProvisioner, and entirely editable afterwards (§5, §72).
        Schema::create('project_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active', 'sort']);
        });

        // The per-project role catalogue (§14). Deliberately separate from `positions`: a person's
        // job title is what they are employed as, a project role is what they do on ONE project.
        Schema::create('project_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->text('description')->nullable();

            // Marks the roles a workflow assignee rule of type `project_role` may resolve to when
            // it says "the lead of this project" — configuration, not a hard-coded title check.
            $table->boolean('is_lead')->default(false);

            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active', 'sort']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Issued by NumberGenerator against the tenant's own scheme (§64). Stored, never
            // recomputed: the project that moves department in March must still be the project
            // everyone quoted in January.
            $table->string('number', 64);
            $table->string('name');
            $table->string('code', 64)->nullable();       // the company's own short handle
            $table->text('description')->nullable();

            $table->foreignId('project_type_id')->nullable()->constrained()->nullOnDelete();

            // Free text until Phase 5 introduces the client register, which adds client_id and
            // backfills from this column. A nullable id pointing at a table that does not exist
            // yet would be a foreign key nothing enforces.
            $table->string('client_name')->nullable();
            $table->string('contract_reference', 120)->nullable();

            // Status lives in the configurable vocabulary, never in an enum (§63).
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            // Gate 5. Drives ScopeCompiler::applyConfidentiality(), so the values must stay the
            // Confidentiality enum's.
            $table->string('confidentiality', 20)->default('internal');

            // The owning unit on the ORG axis. A project belongs to one unit for scope purposes;
            // the departments working on it are project_departments rows, which is a different
            // question with a different answer.
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sponsor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('planned_start')->nullable();
            $table->date('planned_end')->nullable();
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();

            // Financials are separately gated by project.view_financials; storing them here is
            // fine because the columns are stripped from responses the actor may not see.
            $table->decimal('contract_value', 18, 2)->nullable();
            $table->string('currency', 8)->nullable();

            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('health', 16)->nullable();      // on_track|at_risk|off_track
            $table->string('priority', 16)->default('normal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'org_unit_id']);
            $table->index(['organization_id', 'manager_id']);
            $table->index(['organization_id', 'is_active', 'name']);
            $table->index(['organization_id', 'planned_end']);
        });

        Schema::create('project_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('org_unit_id')->constrained('org_units')->cascadeOnDelete();

            // Who in that department answers for the project. An employee rather than a user,
            // because this is an organizational fact, not an account.
            $table->foreignId('lead_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->text('responsibilities')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'org_unit_id']);
            $table->index(['organization_id', 'org_unit_id', 'is_active']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // The visibility grant hangs off user_id: a membership without an account grants
            // nothing, so it is not nullable.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_role_id')->nullable()->constrained()->nullOnDelete();

            // The department this person is on the project FOR. Recorded so a head can see who
            // they have committed, and so removing a department can find its members.
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->text('responsibilities')->nullable();
            $table->unsignedTinyInteger('allocation_percent')->nullable();

            // Time-bounded membership (§12). A consultant assigned for three months loses the
            // project axis the day ends_on passes, with no manual revocation and no loss of the
            // audit entries they wrote while they had it.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);

            // The exact shape ActorProfile::loadProjectIds() queries on every request of every
            // user. It is the hottest index in the access engine.
            $table->index(['user_id', 'is_active', 'starts_on', 'ends_on'], 'project_members_reach');
            $table->index(['organization_id', 'project_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('project_departments');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('project_roles');
        Schema::dropIfExists('project_types');
    }
};
