<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms a company builds for itself, and what people fill into them (§73).
 *
 * THE DIFFERENCE FROM CUSTOM FIELDS, because the two look alike and are not. A custom field adds a
 * question to a register that already exists — a cost code on a project. A form IS the record: a
 * scaffold inspection sheet, a permit to work, a lifting plan, a handover certificate. Every
 * engineering company has a drawer of them, no two companies have the same drawer, and a platform
 * that cannot hold them is a platform used alongside a folder of Word files.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────────
 * THE ONE DECISION EVERYTHING ELSE FOLLOWS FROM: A SUBMISSION BINDS TO A VERSION.
 *
 * A form is edited. That is the whole point of a builder. But a submission is evidence — somebody
 * inspected a scaffold on the 3rd of March and said it was safe — and evidence whose questions can
 * be rewritten afterwards is not evidence. If an answer pointed at the FORM, then adding a question
 * in June would retroactively give every March submission a blank it never had, removing one would
 * orphan what was answered, and changing "Is the scaffold tagged?" to "Is the scaffold untagged?"
 * would silently invert three hundred recorded Yeses.
 *
 * So `form_submissions.form_version_id` is NOT NULL and never changes, versions are immutable once
 * published, and editing a published form opens a new draft version instead. ChecklistTemplate
 * states the same doctrine for onboarding lists — "editing the template in June must not rewrite
 * what a person was asked to do in March" — and this is that rule made structural rather than
 * remembered.
 *
 * The cost is honest and worth naming: a question asked across five versions is five rows, and
 * "how did everyone answer Q4 last year" has to reach through versions by key rather than by id.
 * That is a reporting join. The alternative is a mutable definition, and the failure it produces is
 * an audit finding.
 * ────────────────────────────────────────────────────────────────────────────────────────────
 *
 * ANSWERS ARE ONE TEXT COLUMN, for exactly the reasons custom_field_values is, and the argument is
 * not repeated here — see that migration. What IS different is `row_index`, which is what makes a
 * repeating table a first-class thing rather than a JSON blob: one row per cell, so a table of
 * twelve scaffold bays is twelve rows the database can count, filter and export, and a report can
 * ask "how many bays failed" without parsing anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────── the form's identity
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // What this form is called and where it lives in the list. The NAME may be changed at
            // any time — it is wording, and nothing stores it.
            $table->string('name', 160);
            $table->string('description', 500)->nullable();

            // A stable machine name for exports, reports and anything naming this form from
            // outside. Frozen once a submission exists, like every other key in this build.
            $table->string('key', 64);

            // How the register menu groups it — 'HSE', 'Quality', 'Operations'. A plain string
            // rather than a foreign key: this is a heading, not a record, and a company that wants
            // a new grouping should type it rather than administer it.
            $table->string('category', 60)->nullable();

            // THERE IS DELIBERATELY NO `current_version_id`. The obvious design is a pointer on the
            // form to the edition people are served, and it is two sources of truth: the pointer
            // and the version's own `state` can disagree, and when they do the form either serves
            // an edition it calls retired or refuses to serve one it calls published. Neither
            // failure announces itself.
            //
            // Instead, AT MOST ONE VERSION OF A FORM IS 'published' AT A TIME — publishing retires
            // the one before it, in the same transaction — so "the current version" is a question
            // with one answer, asked of the versions table. See FormDefinitionService.

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'is_active', 'category']);
        });

        // ─────────────────────────────────────────────────────────── one frozen edition of it
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();

            // 1, 2, 3 — what a person reads on a submission to know which questions were asked.
            $table->unsignedSmallInteger('version');

            // 'draft'   — being built, editable, cannot be submitted against
            // 'published' — frozen, accepting submissions
            // 'retired' — frozen, no longer offered, existing submissions still readable
            //
            // A string rather than an enum for the reason every other state column here is one: a
            // new state must never require an ALTER TABLE on a customer's database.
            $table->string('state', 16)->default('draft');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // What changed in this edition, in the author's words. This is what somebody reads a
            // year later when two submissions of the same form disagree about what was asked.
            $table->string('change_note', 500)->nullable();

            $table->timestamps();

            $table->unique(['form_id', 'version']);
            $table->index(['organization_id', 'form_id', 'state']);
        });

        // ─────────────────────────────────────────────────────────── how the page is divided
        Schema::create('form_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();

            $table->string('title', 160);
            $table->string('description', 500)->nullable();

            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['form_version_id', 'sort']);
        });

        // ─────────────────────────────────────────────────────────── the questions
        Schema::create('form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Denormalised from the section on purpose: every read of a version's fields is "all of
            // them, in order", and routing that through sections would make the commonest query a
            // join for no gain.
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_section_id')->constrained()->cascadeOnDelete();

            // A COLUMN OF A REPEATING TABLE, rather than a question in its own right. The parent is
            // a field of type 'table'; its children are the columns, and their answers carry a
            // row_index. One level only — a table inside a table is a spreadsheet, and a form
            // builder that grows one has stopped being a form builder.
            $table->foreignId('parent_field_id')->nullable()
                ->constrained('form_fields')->cascadeOnDelete();

            // What answers are filed under, in exports and across versions. Unique per version, so
            // "question q4" means one thing within an edition; stable ACROSS versions by
            // convention, which is what lets a report follow a question through five editions.
            $table->string('key', 64);
            $table->string('label', 255);

            // See FormField::TYPES. Includes the ones custom fields have, plus 'table' (a repeating
            // group), 'signature' (an approval block), 'heading' and 'note' (which ask nothing).
            $table->string('type', 24);

            $table->string('option_list_key', 64)->nullable();

            $table->string('help', 500)->nullable();
            $table->string('placeholder', 120)->nullable();
            $table->text('default_value')->nullable();

            $table->boolean('is_required')->default(false);

            // Per-type settings that do not each deserve a column: min/max for a number, rows for a
            // long text, whether a date may be in the future. JSON because the set differs per type
            // and a column per setting would be twenty mostly-null columns.
            //
            // NOTHING SECURITY-BEARING LIVES HERE. Validation is compiled from `type`,
            // `is_required` and `option_list_key` — columns — so a crafted config cannot loosen a
            // rule (§84).
            $table->json('config')->nullable();

            // Show this question only when another answer says so. The same narrowing custom fields
            // have, by field key within the version.
            $table->string('applies_when_key', 64)->nullable();
            $table->string('applies_when_value', 120)->nullable();

            $table->unsignedSmallInteger('sort')->default(0);

            $table->timestamps();

            $table->unique(['form_version_id', 'key']);
            $table->index(['form_version_id', 'form_section_id', 'sort']);
            $table->index(['parent_field_id', 'sort']);
        });

        // ─────────────────────────────────────────────────────────── what somebody filled in
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Both, deliberately. The version is what the answers mean; the form is what the
            // submission belongs to, and a listing of "every scaffold inspection" must not have to
            // walk versions to find them.
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained();

            // Issued by the numbering engine, never typed (§64).
            $table->string('number', 64)->nullable();

            // Configurable vocabulary, like every other register (§63).
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            // What this submission is ABOUT, when it is about something: an inspection against a
            // project, a permit against an asset. Optional — plenty of forms are about nothing but
            // themselves — and a morph because it could be any register.
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The access axes. These are what the scope compiler reads, so they are real columns
            // rather than anything derived at read time (§75, §6).
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'form_id', 'status_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['organization_id', 'project_id']);
        });

        // ─────────────────────────────────────────────────────────── the answers
        Schema::create('form_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_submission_id')->constrained()->cascadeOnDelete();

            // Points at the FIELD of the version this submission was made against — so an answer
            // can never be read under a question that was not the one asked.
            $table->foreignId('form_field_id')->constrained()->cascadeOnDelete();

            // 0 for an ordinary question. For a column of a repeating table, which row of the table
            // this cell belongs to. This one small integer is the entire repeating-table design.
            $table->unsignedSmallInteger('row_index')->default(0);

            $table->text('value')->nullable();

            $table->timestamps();

            // One answer per question per row. What makes writing an answer an upsert rather than a
            // read-then-write race.
            $table->unique(['form_submission_id', 'form_field_id', 'row_index'], 'form_answers_unique');

            // The eager-load path: every answer on a submission, one query (§89).
            $table->index(['form_submission_id', 'row_index']);

            // The reporting path: "every answer to this question", across submissions.
            $table->index(['organization_id', 'form_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_answers');
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_fields');
        Schema::dropIfExists('form_sections');
        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('forms');
    }
};
