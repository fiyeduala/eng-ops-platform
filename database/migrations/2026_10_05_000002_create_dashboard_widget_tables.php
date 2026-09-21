<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a company chose to put on its boards (§51, §52, §53, §73).
 *
 * ONE SET OF WIDGETS SERVES EVERY ROLE, and that is the whole design. A company does not build a
 * managing director's dashboard and a site engineer's dashboard; it builds ONE executive board, and
 * each person sees the tiles they may see, carrying the figures their own visibility produces. The
 * alternative — a board per role — is how a platform ends up with fourteen nearly-identical
 * dashboards, one of which was configured in 2026 by somebody who has left and still shows the
 * wrong filter to the finance team.
 *
 * THERE IS NO PERMISSION COLUMN HERE, ON PURPOSE. A widget's required permission is DERIVED from
 * what it shows — a tile counting incidents requires `hse_incident.view`, whatever anybody types on
 * a form. Storing it would make it editable, and an editable permission field on a dashboard is a
 * way to publish a figure under a permission its own register never agreed to (§6, §84).
 *
 * `refresh` IS THE ONE FIELD THAT NEEDS THINKING ABOUT. `live` counts through the viewer's own
 * visibility on every page load: always right, never shared, and twenty of them is twenty scoped
 * aggregates per request (§95). `stored` offers the snapshot the nightly sweep took over the whole
 * scope — but only to somebody whose reach contains that entire scope, which `ReachInspector`
 * decides at render time. A viewer who falls short does not get an error and does not get somebody
 * else's number; they fall back to a live count and a sentence saying it is their own. So the field
 * is a PREFERENCE, never a grant, and misconfiguring it costs page speed rather than privacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // executive | department | project | personal — which board this tile sits on. Not a
            // permission: the board decides which permission is asked for, and a company that
            // never grants `dashboard.view_executive` simply has an executive board nobody opens.
            $table->string('board', 24);

            // kpi_tile | kpi_trend | report_table. See WidgetCatalogue::KINDS.
            $table->string('kind', 24);

            // What this company calls the tile. Optional, falling back to the measure's own name,
            // because the same KPI can be "Open NCRs" on the quality board and "Snags" on the site
            // one, and forcing a single name means one of the two boards speaks a foreign language.
            $table->string('title', 120)->nullable();

            // Exactly one of these is set, depending on `kind`. Separate columns rather than a
            // polymorphic pair because there are two sources and there will not be many more, and
            // a real foreign key is what guarantees a deleted measure takes its tiles with it
            // rather than leaving a board full of blanks nobody can explain.
            $table->foreignId('kpi_definition_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->nullable()->constrained()->cascadeOnDelete();

            // live | stored. A preference, never a grant — see the class note above.
            $table->string('refresh', 16)->default('live');

            // How much of the row this tile takes. Presentation, and the only field here that is.
            $table->string('width', 16)->default('third');

            // For a list tile: how many rows to print. Small on purpose. A dashboard list is a
            // prompt to go and look at the register, not a substitute for opening it, and a
            // hundred-row tile is a report that has escaped its own export rules.
            $table->unsignedSmallInteger('row_limit')->default(5);

            // For a trend: how many points back down the line to draw.
            $table->unsignedSmallInteger('span')->default(12);

            $table->unsignedInteger('sort')->default(0);

            // Switching a tile off rather than deleting it keeps the arrangement recoverable. A
            // board is edited live, by somebody with a meeting in twenty minutes.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The render path: one board, one company, in order.
            $table->index(['organization_id', 'board', 'is_active', 'sort'], 'dashboard_widgets_board_idx');

            // The "what would deleting this measure take with it" question.
            $table->index(['organization_id', 'kpi_definition_id'], 'dashboard_widgets_kpi_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
