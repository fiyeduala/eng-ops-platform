<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The controlled document register (§26, §27, docs/09).
 *
 * THE CENTRAL DECISION, and the one everything else in Phase 3 follows from: a `documents` row is
 * an IDENTITY, and a `document_versions` row is a THING. The document is the number quoted on a
 * drawing, in a transmittal and in a client's letter three years later; the version is what that
 * number actually pointed at on a given day. Every file, every piece of authored content, every
 * approval and every signature attaches to a VERSION. The document itself holds no content at all,
 * only a denormalised pointer at whichever version is current.
 *
 * That split is what makes §26 — "never overwrite an approved document revision" — a property of
 * the schema rather than a rule people have to remember. There is no column on `documents` that a
 * careless update could overwrite, because there is nothing there to overwrite.
 *
 * A version holds ONE OF TWO THINGS, decided by `documents.kind` (see App\Support\Enums\DocumentKind):
 *
 *   NATIVE  → `content_html` + `content_json`. Authored in the app. Diffable revision to revision,
 *             searchable without extraction, exportable to .docx and PDF on demand.
 *   FILE    → `file_path` + `file_hash` + `mime_type`. Deposited from outside. Opaque by nature:
 *             the system versions and guards it without pretending to read it.
 *
 * Both halves are nullable and neither is enforced at the database level, because SQLite (the test
 * driver) and MySQL disagree about CHECK constraint support. The invariant is held in
 * DocumentVersionService, which is the only writer, and asserted in DocumentVersionTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------------- categories

        /*
         * A category is the register's CONFIGURATION, not a folder.
         *
         * "Procedures", "Drawings", "Client Correspondence" and "Vendor Data" do not differ by
         * where they are filed; they differ by how they are numbered, how they are revised, what
         * may be uploaded into them, how sensitive they are by default and which approval route
         * they take. Putting those five answers on the category is what lets a company run a
         * strict ISO-controlled drawing register alongside a casual internal-memo register in one
         * system without a developer (§72).
         */
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('document_categories')->nullOnDelete();

            $table->string('name', 120);
            $table->string('slug', 140);
            // Feeds {TYPE} in a numbering pattern: SPEC, DWG, PROC, MOM.
            $table->string('code', 16)->nullable();
            $table->text('description')->nullable();

            // alpha (A, B, C … AA) or numeric (0, 1, 2 …). Engineering drawing registers overwhelm-
            // ingly use alpha for issued revisions; procedures often use numeric. Per category
            // because one company legitimately runs both.
            $table->string('revision_scheme', 12)->default('alpha');

            // An optional draft series that promotes to the issued series on first approval:
            // P01, P02, P03 … then Revision A. Empty means documents start directly on the issued
            // series. This is how a design office distinguishes "circulated for comment" from
            // "issued", which is a distinction clients care about a great deal.
            $table->string('draft_revision_prefix', 8)->nullable();

            // Overrides settings('document.numbering_format') for this register only — the escape
            // hatch for a client who mandates their own document numbering on their project.
            $table->string('numbering_pattern', 200)->nullable();

            // Upload rules, narrower than the organization default where a register needs it. Null
            // means "use the organization setting", never "allow anything" — FileVault treats null
            // as absent, not as permissive.
            $table->json('allowed_extensions')->nullable();
            $table->unsignedSmallInteger('max_upload_mb')->nullable();

            // What a NEW document in this register defaults to. A default, not a ceiling: the
            // author may raise it, and DocumentPolicy governs whether they may lower it.
            $table->string('default_confidentiality', 20)->default('internal');

            // The default kind for new documents here. A drawings register defaults to `file`; a
            // procedures register defaults to `native`.
            $table->string('default_kind', 12)->default('native');

            // Which approval route a document in this register enters when submitted. Nullable
            // throughout: a category may exist before anybody has configured a workflow, and a
            // register with no approval route is a legitimate configuration, not a broken one.
            // Nullified rather than cascaded on delete — retiring a workflow definition must leave
            // the register working, with new documents simply not auto-routing.
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();

            // Controlled means the register makes promises about it: approval before issue,
            // superseded revisions retained, no silent overwrite. An uncontrolled category is a
            // convenient shared folder and is honest about being one.
            $table->boolean('is_controlled')->default(true);
            $table->boolean('requires_approval')->default(true);

            // Retention clock starts at approval. Null means indefinite; the value is recorded per
            // category because a safety record and a meeting agenda have very different obligations
            // and neither should be hard-coded (§47 — no jurisdiction-specific rules in code).
            $table->unsignedSmallInteger('retention_months')->nullable();

            $table->unsignedInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'is_active', 'sort']);
            $table->index(['organization_id', 'parent_id']);
        });

        // -------------------------------------------------------------------------- documents

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('number', 64);
            $table->string('title', 250);
            $table->text('description')->nullable();

            $table->foreignId('document_category_id')->nullable()->constrained()->nullOnDelete();

            // native | file. Fixed for the document's life — see DocumentKind for why a register
            // whose guarantees vary per revision is worse than one that varies per document.
            $table->string('kind', 12)->default('native');

            // The two access axes, named exactly as the scope compiler expects to find them.
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            // Engineering discipline, as a classification rather than an access axis. Points at
            // org_units because disciplines in this system ARE units — Civil/Structural, Piping,
            // Electrical are real sections with real people in them, and inventing a second table
            // of discipline names would guarantee the two lists drift apart. Its `code` feeds
            // {DISCIPLINE} in the numbering pattern.
            $table->foreignId('discipline_id')->nullable()->constrained('org_units')->nullOnDelete();

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The current revision. A DENORMALISED POINTER, deliberately carrying no foreign key.
             *
             * The constraint would be circular (documents → versions → documents) which MySQL
             * tolerates awkwardly and SQLite cannot add after the fact without rebuilding the
             * table. More importantly it would buy nothing: the invariant that matters is not
             * "this id exists" but "exactly one version per document has is_current = true, and
             * this column names it", which no foreign key can express. DocumentVersionService
             * maintains both inside one transaction and DocumentVersionTest asserts it.
             */
            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->boolean('is_controlled')->default(true);
            $table->date('retention_until')->nullable();
            $table->date('next_review_on')->nullable();
            $table->json('tags')->nullable();

            /*
             * CHECK-OUT. The concurrency answer for a system that cannot use WebSockets (§1).
             *
             * Real-time co-editing needs a persistent connection this platform will not require in
             * V1, and last-writer-wins on a controlled procedure is data loss with an audit trail.
             * So editing is exclusive and explicit: you take the document out, everyone else sees
             * who has it and since when, and you put it back. This is also what engineering
             * document control has always done, so it needs no explaining to the people using it.
             *
             * `lock_expires_at` exists because people go on leave holding a lock. An expired lock
             * is breakable by anyone with `document.edit`; an unexpired one needs `document.manage`,
             * and breaking either is recorded.
             */
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('lock_expires_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'document_category_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'org_unit_id']);
            $table->index(['organization_id', 'discipline_id']);
            $table->index(['organization_id', 'owner_id']);
            // The register's default ordering, and the one query that runs on every page of it.
            $table->index(['organization_id', 'deleted_at', 'number']);
        });

        // --------------------------------------------------------------------------- versions

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // The human revision — "A", "P02", "3". Stored as text because the sequence is
            // configurable per category and "AA" follows "Z".
            $table->string('revision', 16);

            // The machine revision: a monotonic integer that actually sorts. Every "latest
            // revision" query uses this, because ordering by the text would put "P02" after "B"
            // and "AA" before "B".
            $table->unsignedInteger('revision_index')->default(1);

            // True while this version is on the DRAFT series (P01, P02) rather than the issued
            // series. Promotion to the issued series happens once, on first approval.
            $table->boolean('is_draft_series')->default(false);

            // ------------------------------------------------------------------ the file half
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path', 500)->nullable();
            // sha256, recorded at upload. Binds signatures to exact bytes and lets the nightly
            // integrity job prove nothing on disk changed underneath the register.
            $table->string('file_hash', 64)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type', 150)->nullable();
            // Metadata only. The file on disk is named with a ULID — see FileVault — so nothing a
            // user types ever becomes a path component (§67).
            $table->string('original_name', 255)->nullable();

            // --------------------------------------------------------------- the authored half
            // Sanitised HTML: what gets rendered, diffed, exported and printed. Passed through
            // ContentSanitizer on every write without exception — this column is the one place in
            // the application where user input is deliberately stored as markup, so it is also the
            // one place a stored-XSS bug would live.
            $table->longText('content_html')->nullable();

            // The editor's own document model. Kept alongside the HTML rather than instead of it
            // because the editor round-trips its JSON losslessly while HTML parsing is lossy, and
            // because a future editor change must not have to re-parse markup to recover structure.
            // NEVER rendered, never trusted, never the source for export.
            $table->json('content_json')->nullable();

            $table->unsignedInteger('word_count')->nullable();

            // ------------------------------------------------------------------------ metadata
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_summary', 500)->nullable();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();

            $table->boolean('is_current')->default(false);

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            // Which version this one was created from. Makes "compare with the revision I started
            // from" answerable without assuming it was the immediately preceding index, which is
            // false whenever two people revise in parallel off the same approved base.
            $table->unsignedBigInteger('derived_from_version_id')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One row per revision per document, enforced rather than trusted: this is the
            // constraint that stops a double-submitted revision form producing two "Revision C"s.
            $table->unique(['document_id', 'revision']);
            $table->unique(['document_id', 'revision_index']);
            $table->index(['organization_id', 'document_id', 'is_current']);
            $table->index(['organization_id', 'author_id']);
            $table->index(['document_id', 'revision_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_categories');
    }
};
