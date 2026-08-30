<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks, their checklists, their dependencies, and the follower subscription table (§16, §88).
 *
 * A task is the first record in the system that reaches an actor down BOTH visibility axes at
 * once, and it does so without a single line of bespoke resolution code: `project_id` is the
 * column Scopeable::accessProjectColumn() already names, `assignee_id` is the one
 * accessAssigneeColumns() already names, and `org_unit_id` is the org-ladder anchor. That is why
 * the three are spelled exactly like this — the access engine finds them by name, and renaming
 * one silently drops a whole class of people out of their own task list.
 *
 * `followers` is deliberately NOT an access path. A follower row means "notify me about this",
 * nothing else; §68 still requires the notification layer to check that each recipient can
 * actually see the record before it says anything about it. If following granted reach, anyone
 * who could name a record could subscribe themselves into it, which is the whole of the access
 * engine defeated by one button.
 *
 * `task_dependencies` is a graph, and graphs acquire cycles. The service refuses to create one;
 * the unique key here refuses the trivial duplicate; neither is a substitute for the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Issued by NumberGenerator on first save against the tenant's own scheme (§64).
            $table->string('number', 64);
            $table->string('title', 200);
            $table->text('description')->nullable();

            // The project axis. Nullable because plenty of real work — "renew the ISO
            // certification" — belongs to a department and no project at all. When it is null the
            // record is reachable on the org ladder alone, which is the correct answer.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            // Subtasks. Self-referencing and nullable; deleting a parent nulls the children rather
            // than cascading, because losing a parent must never silently destroy work that
            // somebody is still assigned to.
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->nullOnDelete();

            // What this task was raised ABOUT — an incident, an NCR, a document review. Left as a
            // free morph so Phases 3-5 can point their records at it without another migration.
            // It carries no authorization meaning: reach over a task is decided by the task's own
            // columns, never by what it happens to be linked to.
            $table->nullableMorphs('linked');

            // The org-ladder anchor (§11). A task with no unit is reachable by the project axis
            // and by its assignee, which is exactly what an unfiled task should be.
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // The assignee axis. `task.view:assigned` is a real seeded grant, so writing this
            // column IS a visibility decision — TaskPolicy::assign() gates it on both sides.
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Status lives in the configurable vocabulary, never in an enum (§63).
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            // Gate 5, same values as the Confidentiality enum.
            $table->string('confidentiality', 20)->default('internal');
            $table->string('priority', 16)->default('normal');

            $table->date('start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->decimal('actual_hours', 8, 2)->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);

            // "My open tasks" and "this project's board" are the two queries this table exists to
            // answer; both are read on nearly every page a field user opens.
            $table->index(['organization_id', 'assignee_id', 'status_id']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'org_unit_id', 'status_id']);
            $table->index(['organization_id', 'due_on']);
            $table->index(['organization_id', 'parent_id']);
        });

        // A checklist item is part of its task, not a record in its own right: it has no number,
        // no status, no confidentiality of its own, and every authorization question about it is
        // answered by asking TaskPolicy about the parent. organization_id is present anyway so the
        // tenant scope applies at the table level too — defence in depth, not decoration.
        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();

            $table->string('title', 300);
            $table->boolean('is_done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'sort']);
            $table->index(['organization_id', 'task_id', 'is_done']);
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // "This task waits for that one." Both sides cascade: a dependency edge without both
            // of its tasks is not a fact about anything.
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();

            // finish_to_start is the only type the UI offers in V1; the column exists so the other
            // three PERT relations are a configuration change rather than a migration.
            $table->string('type', 24)->default('finish_to_start');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'depends_on_task_id']);
            $table->index(['organization_id', 'depends_on_task_id']);
        });

        // A notification subscription and nothing more. See the class docblock: following a record
        // never grants sight of it, and the notification layer re-checks reach per recipient (§68).
        Schema::create('followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->morphs('followable');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // manual|assignee|creator|mention — why the row exists, so the system can withdraw the
            // ones it added itself without discarding a choice a person made.
            $table->string('reason', 40)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['followable_type', 'followable_id', 'user_id'], 'followers_unique');
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('followers');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('task_checklist_items');
        Schema::dropIfExists('tasks');
    }
};
