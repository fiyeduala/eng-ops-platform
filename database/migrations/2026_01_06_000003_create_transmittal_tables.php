<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transmittals (§38, docs/09 §6) and templates (§65, §66).
 *
 * A TRANSMITTAL is the covering note for documents leaving the building. Its reason for existing is
 * one question, asked years later and usually by a lawyer: "what exactly did we issue to the client
 * on 4 March, and did they confirm receipt?"
 *
 * That question is why `transmittal_items` points at `document_version_id` and NOT at
 * `document_id`. Pinning the document would make the record answer "we sent them ENG-2026-014",
 * which by then reads Revision F and is not what was in the envelope. Pinning the VERSION makes it
 * answer "we sent them ENG-2026-014 Revision C, sha256 abc…", which is the truth and stays the
 * truth however many revisions follow.
 *
 * A TEMPLATE is a document that has not happened yet. Its content is the same sanitised HTML a
 * native document version carries, with placeholders resolved at render time by TemplateRenderer —
 * data substitution through a restricted resolver, never Blade compilation of user input and never
 * eval. A templates screen that can execute code is a remote shell with a nice form around it
 * (§66), so the renderer's whitelist is the security boundary and it is deliberately narrow.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------------ transmittals

        Schema::create('transmittals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('number', 64);
            $table->string('subject', 250);

            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('org_unit_id')->nullable()->constrained('org_units')->nullOnDelete();

            /*
             * WHO IT WENT TO.
             *
             * Deliberately not a foreign key to a `parties` table. Clients and vendors arrive in
             * Phase 5, and half of all real transmittals go to somebody who will never be a record
             * in this system — a regulator, a client's subcontractor, one named engineer at a firm
             * we deal with once. Modelling the recipient as a nullable morph PLUS free text means
             * the module works today, gets stronger when Phase 5 lands, and never forces a user to
             * create a fake client record to send a letter.
             */
            $table->string('to_party_type', 40)->nullable();   // client | vendor | org_unit
            $table->unsignedBigInteger('to_party_id')->nullable();
            $table->string('to_name', 200);
            $table->string('to_attention', 200)->nullable();
            $table->string('to_email', 200)->nullable();
            $table->text('to_address')->nullable();

            // for_approval | for_construction | for_information | for_review | for_tender | as_built
            //
            // The purpose is not decoration: "issued for construction" is a statement with
            // contractual weight, and a register that cannot distinguish it from "issued for
            // comment" is useless in a dispute.
            $table->string('purpose', 32)->default('for_information');

            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->string('confidentiality', 20)->default('internal');

            $table->text('cover_note')->nullable();
            $table->string('transport', 32)->nullable();       // email | portal | courier | hand

            // THE PUBLICATION COLUMN, same machinery as a memo's `issued_at`: a draft transmittal
            // is a list somebody is still assembling, and it must not appear in the register of
            // what has been sent. Declared through Scopeable::accessPublicationColumn().
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('due_response_on')->nullable();

            $table->boolean('requires_acknowledgement')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            // Free text, not a user id: the person who acknowledges is on the OTHER side of the
            // company boundary and has no account here.
            $table->string('acknowledged_by_name', 200)->nullable();
            $table->string('acknowledgement_reference', 200)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'status_id']);
            $table->index(['organization_id', 'project_id']);
            $table->index(['organization_id', 'issued_at']);
            $table->index(['organization_id', 'due_response_on']);
            $table->index(['to_party_type', 'to_party_id']);
        });

        Schema::create('transmittal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transmittal_id')->constrained()->cascadeOnDelete();

            /*
             * The exact revision issued. Restricted on delete rather than cascaded: a version that
             * has been transmitted to a client is evidence, and the database refusing to delete it
             * is the correct behaviour. If a document must go, the transmittal that carried it has
             * to be dealt with deliberately first.
             */
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('copies')->default(1);
            $table->string('media', 32)->default('electronic');  // electronic | paper | both
            $table->string('remarks', 300)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One line per revision per transmittal. Sending the same revision twice on one note is
            // a duplicate row, not a legitimate second entry — `copies` is how you send two.
            $table->unique(['transmittal_id', 'document_version_id']);
            $table->index(['organization_id', 'document_version_id']);
        });

        // ---------------------------------------------------------------------------- templates

        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 150);
            $table->string('slug', 170);
            $table->text('description')->nullable();

            // What this template produces. `document` templates seed a new native document's
            // content; the rest render straight to PDF.
            $table->string('kind', 32)->default('document');

            // When set, offered only for documents in this register — so the drawings register is
            // not cluttered with letter templates.
            $table->foreignId('document_category_id')->nullable()->constrained()->nullOnDelete();

            // Same substance as a native document version, and that is the point (§90): a template
            // IS a document you have not written yet, so it uses the same editor, the same
            // sanitiser and the same renderer rather than a parallel content stack.
            $table->longText('content_html')->nullable();
            $table->json('content_json')->nullable();

            // Rendered above and below every page of the PDF. Separate from the body because they
            // repeat per page, which the body does not.
            $table->longText('header_html')->nullable();
            $table->longText('footer_html')->nullable();

            // Declared placeholders, for the editor's insert menu and for validating a template
            // before it is saved. TemplateRenderer resolves against its own whitelist regardless —
            // this list is an affordance, not the security boundary.
            $table->json('placeholders')->nullable();

            $table->string('page_size', 16)->default('A4');
            $table->string('orientation', 12)->default('portrait');
            $table->json('margins')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'kind', 'is_active']);
            $table->index(['organization_id', 'document_category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
        Schema::dropIfExists('transmittal_items');
        Schema::dropIfExists('transmittals');
    }
};
