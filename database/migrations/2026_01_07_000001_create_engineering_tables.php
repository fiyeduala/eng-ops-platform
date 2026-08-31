<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engineering delivery: deliverables, RFIs, submittals and change requests (Phase 4).
 *
 * FOUR REGISTERS, ONE SHAPE. Every table here carries the same spine — organization, number,
 * project, discipline, owning unit, status, confidentiality, raised-by, dates — because they are
 * the same kind of object seen from four angles, and because that spine is what the access
 * compiler, the workflow engine, the status machine and the numbering engine already know how to
 * read. A register that invented its own column names would need its own everything (§90).
 *
 * DISCIPLINE IS AN ORG UNIT, not a lookup table of its own, exactly as it is on `documents`. A
 * company's disciplines ARE its engineering departments — Civil/Structural, Piping, Electrical,
 * Instrumentation — and keeping a parallel list would guarantee the two drift apart the first time
 * somebody adds a Process department without adding a Process discipline.
 *
 * THE PARTY MORPH IS DELIBERATELY EMPTY IN THIS PHASE. An RFI goes to a client; a submittal goes to
 * a client or a vendor; neither exists as a table until Phase 5. Rather than model the outside
 * world as a string now and migrate it later, the morph is here from the start and `party_name`
 * carries the text a user types today. When Phase 5 lands, existing rows keep their name and new
 * ones point at a real record — no migration of live correspondence.
 *
 * THE DRAWING REGISTER STORES NO FILES. `drawings` is title-block metadata — sheet number, sheet
 * size, scale, revision, IFR/IFA/IFC state — and a pointer at the controlled document that holds the
 * actual file. Revisions, signatures, transmittals and downloads all stay in the document module,
 * because a second file store would fragment exactly the thing document control exists to hold
 * together. What justifies the separate table is that none of those columns belong on `documents`
 * (a memo has no sheet size) and that a drawing register is routinely maintained months before any
 * file exists, exactly like the deliverable register above it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The Master Deliverable Register: what this project has PROMISED to produce.
         *
         * A deliverable is not a document. It is the commitment — "Rev 0 of the piping GA drawing,
         * issued for construction by 14 March, worth 2% of the discipline's scope" — and it exists
         * from the day the schedule is drawn, months before anybody creates the file. That gap is
         * the whole reason this table is separate from `documents`: progress on a project is
         * measured against what was promised, and a register that can only count what already
         * exists can never report that something is late.
         *
         * `document_id` closes the loop when the artefact appears. Nullable forever, because plenty
         * of deliverables are satisfied by something outside document control.
         */
        Schema::create('deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            // Not nullable, unlike tasks. A deliverable with no project is not a promise to
            // anybody — the register exists to answer "what does THIS project owe".
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // Free text against a configurable list (`deliverable.types`), not an enum: one company
            // says "Datasheet" where another says "Data Sheet" and a third has "Model", and none of
            // them should need a migration to say so (§73).
            $table->string('type', 60)->nullable();

            // The artefact, once it exists. nullOnDelete rather than cascade: deleting a document
            // must never quietly delete the record that it was owed.
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('planned_start_on')->nullable();
            $table->date('planned_issue_on')->nullable();
            // What we now expect, as distinct from what we promised. Keeping both is what makes a
            // slippage report possible at all; overwriting the plan with the forecast is how a
            // project reports itself on time until the week it is not.
            $table->date('forecast_issue_on')->nullable();
            $table->date('actual_issue_on')->nullable();

            // Relative size within the project, for weighted progress. Defaults to 1 so a register
            // that never sets it still rolls up as a simple count.
            $table->decimal('weight', 8, 2)->default(1);
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->string('revision', 16)->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'discipline_id']);
            $table->index(['organization_id', 'responsible_id']);
            // "What is due this month" and "what is late" are the two queries the register exists
            // for, and both read this column before anything else.
            $table->index(['organization_id', 'planned_issue_on']);
        });

        /*
         * The drawing register: the title block, as data.
         *
         * Everything here is something a drawing has and a memo does not — a sheet number, a sheet
         * size, a scale, a revision that means IFR/IFA/IFC rather than "version 4". None of it
         * belongs on `documents`, and all of it is what an engineer means when they say "check the
         * register".
         *
         * `document_id` IS THE FILE, and this table never holds one. That boundary is the whole
         * design: revisions, signatures, transmittals, downloads and the private-storage rules in
         * §67 all stay in the document module, so there is exactly one place a controlled file can
         * live and exactly one set of rules deciding who may open it. Nullable, because a register
         * line is routinely created from the drawing schedule months before anybody starts drawing.
         *
         * `deliverable_id` links the drawing to the promise it satisfies, when there is one.
         */
        Schema::create('drawings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);                  // the drawing number
            $table->string('title', 300);

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // The controlled file. nullOnDelete: removing a document must not delete the register
            // line that records the drawing was ever owed.
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deliverable_id')->nullable()->constrained()->nullOnDelete();

            // Title-block facts.
            $table->unsignedSmallInteger('sheet_number')->nullable();
            $table->unsignedSmallInteger('sheet_count')->nullable();
            $table->string('sheet_size', 16)->nullable();  // A0 … A4, ANSI D, custom
            $table->string('scale', 32)->nullable();       // "1:50", "NTS"
            $table->string('revision', 16)->nullable();

            // Issue purpose — IFR, IFA, IFC, AB. A string against a configurable list rather than
            // an enum: the abbreviations are a contract's vocabulary, and projects differ.
            $table->string('purpose', 24)->nullable();
            $table->date('revision_date')->nullable();

            $table->foreignId('drawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'discipline_id']);
            $table->index(['organization_id', 'deliverable_id']);
            $table->index(['organization_id', 'document_id']);
        });

        /*
         * Requests for information, technical queries and site queries.
         *
         * ONE TABLE, THREE KINDS. They differ in who is asking whom — a contractor asks the client
         * (RFI), a site asks engineering (TQ), a discipline asks another discipline (site query) —
         * and in nothing else: every one is a dated question with a required-by date, an answer, and
         * an argument about whether the answer costs money. Splitting them would give three
         * registers nobody can search across, which is the opposite of what the person chasing an
         * unanswered question needs.
         */
        Schema::create('rfis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);

            $table->string('kind', 24)->default('rfi');           // RfiKind
            $table->string('direction', 16)->default('outgoing'); // we asked | we were asked

            $table->string('subject', 300);
            $table->text('question');

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // Who it is addressed to. See the class docblock: the morph waits for Phase 5.
            $table->nullableMorphs('party');
            $table->string('party_name', 200)->nullable();

            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('raised_on')->nullable();
            $table->date('required_by')->nullable();

            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();

            // The commercial tail. Recorded as flags plus a note rather than as money, because at
            // the moment an RFI is answered nobody knows the number yet — and a nullable amount
            // invites somebody to guess one into a field that later gets summed.
            $table->boolean('cost_impact')->default(false);
            $table->boolean('schedule_impact')->default(false);
            $table->text('impact_notes')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'kind', 'status_id']);
            // The overdue-RFI report, which is the one somebody runs every Monday morning.
            $table->index(['organization_id', 'required_by', 'answered_at']);
            $table->index(['organization_id', 'raised_by']);
        });

        /*
         * Submittals: material, shop-drawing, method-statement and vendor-data packages sent out
         * for review, and the coded verdict that comes back.
         *
         * A submittal is not a transmittal. A transmittal says "here are the documents we sent you
         * on the 4th"; a submittal is an open question — "do you accept this pump" — that stays open
         * until a review code arrives, and that is frequently answered with "revise and resubmit",
         * which is why `resubmittal_of_id` exists. The chain of resubmittals against one item is the
         * evidence in every schedule dispute that follows.
         */
        Schema::create('submittals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description')->nullable();

            $table->string('type', 60)->nullable();   // configurable list, as on deliverables
            $table->string('revision', 16)->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->nullableMorphs('party');
            $table->string('party_name', 200)->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('submitted_on')->nullable();
            $table->date('required_by')->nullable();

            // The review code the reviewer returned — "A", "1", "Code 2", whatever this client
            // uses. A string against a configurable list (`submittal.review_codes`) because the
            // codes are the client's vocabulary, not ours, and a project can change client.
            $table->string('review_code', 24)->nullable();
            $table->text('review_comment')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Points at the submittal this one replaces. nullOnDelete keeps the newer record
            // meaningful if an early attempt is ever removed.
            $table->foreignId('resubmittal_of_id')->nullable()->constrained('submittals')->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'required_by', 'reviewed_at']);
            $table->index(['organization_id', 'resubmittal_of_id']);
        });

        /*
         * What is actually in the package.
         *
         * Points at a document VERSION, like transmittal_items, so "which revision did they code B"
         * is answerable years later. Nullable, because a submittal frequently carries a vendor's
         * catalogue page that was never brought into document control — and a register that refuses
         * to record that is a register people keep in a spreadsheet instead.
         */
        Schema::create('submittal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submittal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description', 300);
            $table->string('reference', 120)->nullable();   // tag number, spec clause, item code
            $table->unsignedSmallInteger('quantity')->default(1);

            // A per-item verdict. Clients routinely accept nine items and reject the tenth, and a
            // single code on the parent cannot say that.
            $table->string('review_code', 24)->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['submittal_id', 'sort']);
            $table->index(['organization_id', 'document_version_id']);

            // The same revision twice in one package reads, to a reviewer, as two things to
            // review. Nulls are distinct in both MySQL and SQLite, which is exactly what is wanted
            // here — a package may hold any number of lines that name no revision at all (a
            // physical sample, a mock-up, a certificate nobody has registered yet).
            $table->unique(['submittal_id', 'document_version_id'], 'submittal_items_unique_revision');
        });

        /*
         * Engineering change requests.
         *
         * The record that says the design changed, why, whose fault it was and what it cost. Its
         * `disposition` is separate from its `status` on purpose: status is where the request has
         * got to in the company's own process, disposition is the answer. A request can be closed
         * (status) as rejected (disposition), and reporting needs both — "how many changes did we
         * approve" is a different question from "how many are still open".
         */
        Schema::create('change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('number', 64);
            $table->string('title', 300);
            $table->text('description');
            $table->text('reason')->nullable();

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->string('origin', 40)->nullable();    // client | internal | vendor | site | regulatory

            // Impact as the requester estimates it. Money is a plain decimal with no currency
            // column: the project carries the currency, and duplicating it here would let the two
            // disagree. §47 — no tax or jurisdiction rules are implied by this column.
            $table->decimal('cost_impact', 16, 2)->nullable();
            $table->integer('schedule_impact_days')->nullable();
            $table->text('impact_notes')->nullable();

            $table->string('disposition', 24)->nullable();  // approved | rejected | deferred | withdrawn
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('raised_on')->nullable();
            $table->date('required_by')->nullable();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'project_id', 'status_id']);
            $table->index(['organization_id', 'disposition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
        Schema::dropIfExists('submittal_items');
        Schema::dropIfExists('submittals');
        Schema::dropIfExists('rfis');
        Schema::dropIfExists('drawings');
        Schema::dropIfExists('deliverables');
    }
};
