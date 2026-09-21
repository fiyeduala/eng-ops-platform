<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first stored figures in the platform, and the columns that make storing them safe.
 *
 * Phase 7 cached nothing: every KPI was counted live, through the visibility of whoever asked. This
 * table is the deliberate exception, and three columns carry the whole justification.
 *
 * `scope_type` and `scope_id` say WHAT WAS COUNTED — a company, a department subtree, a project —
 * and never who it was counted for. That is the difference between a figure that can be shown to a
 * second person and one that cannot. `ReachInspector` decides at read time whether a given viewer's
 * reach contains that scope; a viewer whose reach falls short gets their own live figure instead.
 *
 * `entity_type` is copied from the definition rather than read through it. A snapshot outlives the
 * definition's current shape, and a definition repointed at another register would otherwise
 * relabel every historical point on a trend line with a number counted from a different table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kpi_definition_id')->constrained()->cascadeOnDelete();

            // The register counted, frozen at the moment of counting. See the class note above.
            $table->string('entity_type', 64);

            // organization | department | project — the same vocabulary as KpiDefinition::SCOPES,
            // minus `employee`. A per-person figure is never snapshotted: it is by definition a
            // number counted about one named individual, which is the exact thing this table's
            // read rule cannot make safe to hand to a second person, and it is performance data
            // besides. Employee-scoped KPIs stay live-only.
            $table->string('scope_type', 16);

            // Null for an organization-wide point. Deliberately NOT a foreign key: a department
            // that is dissolved or a project that is closed must not take its history with it,
            // because "what was the figure before the reorganization" is the question a trend line
            // exists to answer.
            $table->unsignedBigInteger('scope_id')->nullable();

            // The period this point belongs to, in the definition's own period vocabulary —
            // "2026-09", "2026-W38", "2026-09-21". Text rather than a date because a period is a
            // label, and two definitions on different periods must not collide on one day.
            $table->string('period_key', 32);

            $table->decimal('value', 20, 4)->nullable();

            // Null value and a reason is a real outcome, not a failure to record one: a measure
            // that could not be counted this morning must not silently reuse yesterday's number.
            $table->string('unavailable_reason')->nullable();

            $table->timestamp('computed_at');
            $table->timestamps();

            // One point per measure per period per scope. This is what makes the sweep idempotent:
            // a second run on the same day updates in place rather than drawing the line twice.
            $table->unique(
                ['organization_id', 'kpi_definition_id', 'period_key', 'scope_type', 'scope_id'],
                'kpi_snapshots_point_unique'
            );

            $table->index(['organization_id', 'kpi_definition_id', 'period_key'], 'kpi_snapshots_series_idx');
            $table->index(['organization_id', 'scope_type', 'scope_id'], 'kpi_snapshots_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_snapshots');
    }
};
