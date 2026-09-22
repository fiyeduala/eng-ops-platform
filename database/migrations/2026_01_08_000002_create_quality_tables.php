<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality: inspections and non-conformance (Phase 4).
 *
 * TWO REGISTERS THAT ARE HALVES OF ONE SENTENCE. A quality inspection asks "does this meet the
 * specification"; an NCR is what exists when the answer is no. They are separate tables rather than
 * one because an NCR outlives the inspection that found it by months, carries its own disposition,
 * its own cost and its own closure, and arrives just as often from a client complaint, a supplier
 * audit or a site engineer's own eye as it does from a scheduled inspection.
 *
 * THE INSPECTION REGISTER IS ALSO THE REQUEST REGISTER. In construction the same record is raised
 * as a request for inspection ("the rebar is ready, come and look before we pour"), scheduled,
 * attended, and then carries the result. Splitting request from result would double the register
 * and lose the one number everybody wants from it: how long between asking and being inspected.
 *
 * THE DISPOSITION IS NOT THE STATUS, exactly as on change requests. Status is where the NCR has got
 * to in this company's process; disposition is the engineering answer — rework, repair, use as is,
 * reject, regrade. An NCR can be closed (status) as use-as-is (disposition), and "how much did we
 * accept as-is this quarter" is a question no status column can answer.
 *
 * USE-AS-IS IS WHY DISPOSITION NEEDS A JUSTIFICATION. Accepting non-conforming work is a legitimate
 * and common engineering decision, and it is also the one most likely to be questioned years later.
 * The service refuses a use-as-is or repair disposition with no notes, for the same reason a change
 * request refuses an unexplained rejection.
 *
 * CORRECTIVE ACTIONS ARE NOT DUPLICATED HERE. An NCR that needs one opens a row in
 * `corrective_actions` through the same `create_record` workflow verb an incident uses — same
 * register, same "what is outstanding against me" query, one place to look (§90).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * "Come and look at this before we cover it up."
         *
         * `scheduled_for` AND `inspected_at` ARE BOTH KEPT, and the gap between them is the metric
         * the register exists to expose: work stops while an inspection is outstanding, so a
         * company that cannot measure its own response time cannot explain its programme.
         *
         * `witnessed_by` IS FREE TEXT because the witness is usually the client's or the
         * certifying authority's representative, and neither has a login here until Phase 5 gives
         * clients their own records. Same reasoning as `party_name` on the engineering registers:
         * model the outside world as text now, point at a record later, migrate nothing.
         */
        Schema::create('quality_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // `quality_inspection.types` — request for inspection, witness point, hold point,
            // surveillance, final. Which of these a company uses is contractual, not universal.
            $table->string('inspection_type', 60)->nullable();

            // The Inspection and Test Plan line this inspection discharges. A string rather than a
            // foreign key: ITPs are documents in document control, and their line references are
            // client-formatted text ("ITP-CIV-004 §3.2") that no lookup table would survive.
            $table->string('itp_reference', 120)->nullable();
            $table->string('activity', 300)->nullable();
            $table->string('location', 200)->nullable();

            $table->dateTime('scheduled_for')->nullable();
            $table->date('required_by')->nullable();

            $table->dateTime('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('witnessed_by', 200)->nullable();

            // `quality_inspection.results` — accepted, accepted with comments, rejected.
            $table->string('result', 60)->nullable();
            $table->text('comments')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'scheduled_for']);
            $table->index(['organization_id', 'result']);
        });

        /*
         * The checkpoints on one inspection.
         *
         * `acceptance_criteria` AND `measured_value` ARE SEPARATE COLUMNS because an inspection
         * record that says only "pass" is worthless the moment somebody disputes it. The criterion
         * is what the specification demanded; the measurement is what was found. Both are text —
         * a torque figure, a concrete slump, a coating thickness and a bolt count are not one type.
         */
        Schema::create('quality_inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quality_inspection_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->string('reference', 60)->nullable();
            $table->text('description');
            $table->text('acceptance_criteria')->nullable();

            // Same list as the parent's, plus "not applicable" — see the HSE inspection items for
            // why a boolean here would score unlooked-at lines as passes.
            $table->string('result', 40)->nullable();
            $table->string('measured_value', 200)->nullable();
            $table->text('comment')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'quality_inspection_id', 'sort_order'], 'quality_inspection_items_org_inspection_sort_index');
        });

        /*
         * It does not meet the specification.
         *
         * `requirement` IS ITS OWN COLUMN AND IT MATTERS MORE THAN THE DESCRIPTION. An NCR that says
         * "welds are bad" is an opinion; one that says "AWS D1.1 Table 6.1 requires undercut ≤ 1mm;
         * measured 2.5mm" is a finding. Forcing the clause into a field of its own is the cheapest
         * quality-management intervention this schema can make.
         *
         * SEVERITY DRIVES ROUTING, which is why it is a configurable string and a whitelisted
         * workflow condition field. "Route critical NCRs to the quality manager and minor ones to
         * the site engineer" is the first thing anybody configures here.
         *
         * THE COST COLUMN IS NULLABLE AND STAYS NULLABLE. Most NCRs never have a costed figure, and
         * a default of zero would make the quarterly total read as "non-conformance cost us
         * nothing" rather than "we did not price it".
         */
        Schema::create('ncrs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // Where it came from. `quality_inspection_id` when an inspection found it; `source`
            // covers the rest — client, supplier, internal audit, site observation.
            $table->foreignId('quality_inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 60)->nullable();

            $table->string('severity', 40)->nullable();
            $table->string('category', 60)->nullable();
            $table->string('location', 200)->nullable();

            // The clause that was breached. See the docblock — this is the column that turns an
            // opinion into a finding.
            $table->text('requirement')->nullable();

            // Who the finding is against. Free text for now, for the same reason as
            // `quality_inspections.witnessed_by`: subcontractors and suppliers get their own
            // records in Phase 5, and existing rows keep their text when they do.
            $table->string('party_name', 200)->nullable();

            $table->date('detected_on')->nullable();
            $table->foreignId('detected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('required_by')->nullable();

            // The other side's account of it. An NCR that records only the raiser's version is half
            // a document, and the response frequently changes the disposition.
            $table->text('response')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();

            // `ncr.dispositions` — rework, repair, use as is, reject, regrade. Not the status; see
            // the class docblock.
            $table->string('disposition', 60)->nullable();
            $table->text('disposition_notes')->nullable();
            $table->dateTime('dispositioned_at')->nullable();
            $table->foreignId('dispositioned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('cost_impact', 14, 2)->nullable();

            $table->dateTime('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable();

            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closure_notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'severity', 'status_id']);
            $table->index(['organization_id', 'required_by']);
            $table->index(['organization_id', 'quality_inspection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ncrs');
        Schema::dropIfExists('quality_inspection_items');
        Schema::dropIfExists('quality_inspections');
    }
};
