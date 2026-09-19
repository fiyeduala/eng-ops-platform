<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a company counts, said by the company (§56, §73).
 *
 * A KPI IS A QUESTION EXPRESSED AS DATA, NEVER AS SQL. Every row here is a noun, a verb, a set of
 * conditions and a window — "how many deliverables, submitted late, this month" — assembled from
 * lists the platform offers. There is no expression column, no formula column and nowhere at all to
 * type a fragment of a query, because a configuration screen that accepts SQL is a remote shell with
 * a nice form around it, and the person filling it in is an administrator rather than a developer.
 * docs/11 §5 states the rule as "a whitelisted entity + column descriptor, never raw SQL"; every
 * column below that names a field is validated against KpiCatalogue on the way in and again on the
 * way out (§84).
 *
 * NO STORED VALUE. This table holds definitions and nothing else — no total, no "last computed",
 * no cached number. A KPI is re-counted for whoever is looking, through their own visibility, at
 * the moment they look, which is the only way the figure can obey §61: two people opening the same
 * KPI must see the two different numbers their two different scopes justify, and a shared stored
 * total could only ever be one of them. Trend snapshots, which are a different thing with a
 * different reason to exist, come later and get their own table.
 *
 * WHY entity_type AND NOT "module". docs/11 writes `module`; this platform already has a word for
 * exactly this string and uses it in the status tables, the numbering schemes, the custom fields,
 * the workflow definitions and the expiry watches. Spelling it a sixth way here would mean the one
 * table where joining to the others requires knowing they are the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // What the company calls this number. It appears on dashboards and in the daily report,
            // so it is the company's phrase and not the platform's: a firm that says "snags" should
            // never be shown a tile headed "Non-Conformance Reports".
            $table->string('name', 120);

            // Why it is being counted, in the company's own words. Optional, and worth having: the
            // person who reads a KPI in eighteen months is rarely the person who defined it, and
            // "open corrective actions" does not say whether verified-but-not-closed ones count.
            $table->text('description')->nullable();

            // The register being counted — 'deliverable', 'hse_incident' — the same morph key the
            // rest of the platform uses. Never a class name and never a table name.
            $table->string('entity_type', 64);

            // count | sum | avg | ratio | percent. See KpiDefinition::AGGREGATES.
            $table->string('aggregate', 24);

            // The column summed or averaged. Null for count, ratio and percent, which count rows.
            // Checked against the numeric columns the register actually offers, never against the
            // request: a column name arriving from a select box is a column name from the browser,
            // and one reaching an aggregate unchecked would be a way to ask about any column at all.
            $table->string('measure_field', 64)->nullable();

            // The conditions every counted record must meet: a list of {field, operator, value},
            // each field drawn from the register's own condition whitelist — the same structured,
            // free-text-free list the workflow builder branches on, and for the same reason. A
            // filter that could test the words inside a confidential incident would be a way to
            // read them back out one guess at a time (§61).
            $table->json('filters')->nullable();

            // The extra conditions the NUMERATOR must also meet, for ratio and percent. "Closed
            // within the target" over "closed at all" is two filter sets on one register rather
            // than two KPIs divided by hand, which is what makes the figure re-checkable.
            $table->json('numerator_filters')->nullable();

            // Which date the period window applies to, e.g. 'created_at', 'closed_on'. Null means
            // the window is ignored and every record is counted — right for "open items", which is
            // a position rather than a flow.
            $table->string('date_field', 64)->nullable();

            // all_time | day | week | month | quarter | year. The window is computed from the date
            // the KPI is asked for, never stored, so a KPI asked about last March answers about
            // last March without anybody having defined a second KPI.
            $table->string('period', 24)->default('all_time');

            // organization | department | project | employee — what one instance of this number is
            // ABOUT. A company-wide incident count and a per-project incident count are the same
            // question asked at two altitudes, and saying which lets a dashboard put the right one
            // on a project page without a second definition.
            $table->string('scope', 24)->default('organization');

            // What good looks like, and which direction is good. `direction` is not decoration: it
            // is the difference between a red tile and a green one, and a platform guessing that
            // bigger is better would quietly congratulate a company on its incident rate.
            $table->decimal('target', 18, 4)->nullable();
            $table->string('unit', 24)->nullable();
            $table->string('direction', 16)->default('higher_better');

            // {"warning": x, "critical": y} — where the tile changes colour. Read through
            // `direction`, so the same two numbers mean opposite things on a safety KPI and a
            // productivity one without the administrator having to think about it.
            $table->json('thresholds')->nullable();

            $table->boolean('is_active')->default(true);

            // The company's own ordering on the dashboard. Its own, because which three numbers go
            // at the top is a statement about what the company cares about this year.
            $table->integer('sort')->default(0);

            $table->timestamps();

            // One name per company. Two KPIs called "Completion rate" measuring different things is
            // the failure this table exists to prevent: a number nobody can argue with because
            // nobody can say which one it is.
            $table->unique(['organization_id', 'name'], 'kpi_definitions_unique_name');

            // The dashboard's path: every active KPI for one company, in order.
            $table->index(['organization_id', 'is_active', 'sort']);

            // The "what does this register feed" question, asked when a module is retired.
            $table->index(['organization_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_definitions');
    }
};
