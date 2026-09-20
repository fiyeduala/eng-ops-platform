<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a company asks of its own records, said by the company (§73, docs/11 §1).
 *
 * A REPORT IS A QUESTION EXPRESSED AS DATA, NEVER AS SQL. The rule the KPI table states is repeated
 * here in full force, because a report builder is where the temptation to add "just a WHERE clause
 * box" is strongest — the request always sounds reasonable and it is always a remote shell with a
 * nice form around it. There is no expression column below, no formula column, and nowhere to type
 * a fragment of a query. Every column that names a field holds a key from ReportCatalogue, checked
 * on the way in and again on the way out (§84).
 *
 * NO STORED ROWS, AND NO STORED ROW COUNT. This table holds definitions. It does not hold results,
 * a "last run" total or a cached page, and the omission is load-bearing rather than incidental: a
 * report's rows are whatever the person running it is allowed to see, so a stored result belongs to
 * exactly one reader and handing it to a second is the disclosure §61 exists to prevent. The
 * kpi_definitions table makes the same omission for the same reason, and the note there about
 * snapshots applies here too — a saved *export*, which is a different thing, is a file on private
 * storage owned by the person who asked for it, not a row in this table.
 *
 * WHY THIS IS NOT `saved_filters`. docs/11 §2 describes per-user saved filter sets, shareable within
 * scope, and those are a separate table with a separate owner column and a separate lifetime. This
 * one is company configuration: it is built by somebody with `report.create`, it appears for
 * everybody with `report.view`, and it answers differently for each of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // What the company calls this report. It appears in the reports list, at the head of
            // every export and in the audit entry written when somebody takes a copy away.
            $table->string('name', 120);

            // What it is for, in the company's own words. The person opening a report in two years
            // is rarely the person who built it, and "outstanding RFIs" does not say whether ones
            // answered-but-not-accepted are in or out.
            $table->text('description')->nullable();

            // The register being listed — 'deliverable', 'rfi' — the same morph key the status
            // tables, numbering schemes, custom fields, workflows, expiry watches and KPI
            // definitions all use. Never a class name, never a table name.
            $table->string('entity_type', 64);

            // The columns printed, in the order they are printed: a list of field keys drawn from
            // ReportCatalogue::columnsFor(). This list is deliberately WIDER than the filter
            // whitelist below — a title may be shown and may not be searched on — and the argument
            // for that asymmetry is written out in ReportCatalogue.
            $table->json('columns')->nullable();

            // The conditions every listed row must meet: {field, operator, value}, each field drawn
            // from the register's structured-field whitelist. The same list the workflow builder
            // branches on and the KPI builder filters on, unchanged and un-extended, because a
            // filter that can test free text reads it back one guess at a time (§61).
            $table->json('filters')->nullable();

            // One field to group rows under, or null for a flat list. Drawn from the filterable
            // fields plus the date fields — never from the free-text columns, which would both
            // produce a page of one-row groups and hand back the distinct values of a column
            // nobody was allowed to filter on.
            $table->string('group_field', 64)->nullable();

            // How the rows are ordered. Any printable column may be sorted on: ordering reveals
            // the order of values the reader has already been shown, which is not the fresh
            // disclosure that filtering is.
            $table->string('sort_field', 64)->nullable();
            $table->string('sort_direction', 8)->default('desc');

            // The most rows one run returns, per ReportDefinition::MAX_ROWS. A ceiling rather than
            // a page size: the queued-export path docs/11 §3 describes for large sets is Phase 8,
            // and until it exists a report that would return a hundred thousand rows says so and
            // stops instead of proving it could (§95).
            $table->integer('row_limit')->default(500);

            $table->boolean('is_active')->default(true);

            // The company's own ordering of its reports list.
            $table->integer('sort')->default(0);

            // Who built it. Not an access control — `report.view` governs who may open this, and
            // the ROWS are governed by the reader's own visibility — but the question "who decided
            // this is how we measure ourselves" has an owner, and it belongs in the audit trail.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One name per company. Two reports called "Monthly progress" showing different things
            // is the failure this prevents: a figure nobody can argue with because nobody can say
            // which report it came from.
            $table->unique(['organization_id', 'name'], 'report_definitions_unique_name');

            // The reports list's own path: every active report for one company, in order.
            $table->index(['organization_id', 'is_active', 'sort']);

            // "What does this register feed", asked before a module is retired.
            $table->index(['organization_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_definitions');
    }
};
