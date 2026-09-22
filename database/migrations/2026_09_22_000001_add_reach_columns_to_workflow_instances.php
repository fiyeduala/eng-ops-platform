<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the thing being approved lives, recorded on the approval itself (§6, §25, §78).
 *
 * THE DEFECT THIS CLOSES. `ScopeCompiler` resolves a unit- or project-scoped grant by finding the
 * record's own `org_unit_id` / `project_id`, or — when a model has neither — by recursing through
 * `accessParentRelation()` until it reaches a model that does. A workflow instance had no unit
 * column, no project column and no reachable parent: its subject hangs off a polymorphic
 * `subject_type`/`subject_id` pair, and `whereHas()` cannot walk a morphTo. So the compiler fell
 * through to its last branch and wrote `1 = 0`. Every unit- or project-scoped `approval.*` grant in
 * the system evaluated to "no rows", silently, in exactly the same SQL the index page uses.
 *
 * WHAT THAT MEANT IN A PROVISIONED COMPANY. Six of the shipped role templates grant approval
 * authority at department or project scope — Department Head, Engineering Manager, Lead Engineer,
 * Project Director, Project Manager, HSE Officer. Stage resolution put them on stages correctly,
 * because resolution reads the org chart rather than the permission compiler. The notification went
 * out. And then `WorkflowStageInstancePolicy::decide()` asked the compiler whether the record was
 * within reach and was told it was not, so the decision returned 403 — and a Department Head's
 * approvals inbox, which is the same query, was empty. An approval addressed to somebody who cannot
 * open it is a workflow that stops with nobody able to say why, which is the failure §25's
 * escalation ladder exists to make noisy rather than the one it quietly becomes.
 *
 * WHY DENORMALISE RATHER THAN TEACH THE COMPILER ABOUT MORPHS. `whereHasMorph()` needs the list of
 * subject types up front and produces a UNION per type on every authorization check, on the hottest
 * query in the application — the approvals inbox. Two nullable columns, written once per stage
 * transition, answer the same question with an index. The engine keeps them in step: they are set
 * when the instance starts and refreshed every time it advances, so a document that changes
 * department mid-route carries its approval with it (§74) rather than leaving it addressed to the
 * department it used to be in.
 *
 * NOTHING IS BACKFILLED, deliberately. The columns are written by the engine, and the only rows
 * that could predate them are in a database that has never been deployed — Phase 9 is the phase in
 * which the production first-run path is being built. A running instance created before this
 * migration picks its values up on its next transition; a stalled one would not, which is a reason
 * to avoid shipping this after a real deployment rather than a reason to guess values now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            // Nullified rather than cascaded on both: deleting an org unit or closing a project
            // must not erase the record that somebody approved something. The approval falls back
            // to organization reach, which is wider than intended but never destructive.
            $table->foreignId('org_unit_id')->nullable()->after('subject_id')
                ->constrained('org_units')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->after('org_unit_id')
                ->constrained('projects')->nullOnDelete();

            // The approvals inbox filters by tenant and then by unit subtree; this is that query.
            $table->index(['organization_id', 'org_unit_id']);
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_instances', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'org_unit_id']);
            $table->dropIndex(['organization_id', 'project_id']);
            $table->dropConstrainedForeignId('org_unit_id');
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
