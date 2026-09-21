<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A file that has left the screen but not yet left the building (docs/11 §3, §67).
 *
 * THE MOST IMPORTANT COLUMN IS `requested_by`, AND IT IS NOT AN AUDIT FIELD. Everywhere else in the
 * platform a record belongs to a company and is shown to whoever holds the permission. An export
 * file belongs to one PERSON, because what is inside it is that person's visibility frozen at the
 * moment they clicked: a department manager's export of a company report is a department's worth of
 * rows, and handing that same file to somebody else means handing them a set of rows that neither
 * their permissions nor the file itself explains (§61). So `requested_by` is the access control,
 * ExportPolicy reads no permission at all, and an administrator cannot download somebody else's
 * export — not because administrators are not trusted, but because the file does not say whose view
 * it is once it is open.
 *
 * `expires_at` IS THE POINT OF THE TABLE. §67 puts stored files on private storage behind an
 * authorized download; a report export is worse than an uploaded document in one specific way — the
 * document was always going to exist, and this is a copy that exists only because somebody wanted it
 * for an afternoon. Keeping it forever turns a reporting feature into an unmanaged archive of
 * everyone's old visibility, which is precisely how a leak stays current years after the permission
 * that caused it was revoked. The sweep deletes the file and keeps the row: what the file contained
 * is still an audit question after the bytes are gone.
 *
 * `report_definition_id` is nullable and `nullOnDelete`. Deleting a report must not delete the trail
 * of what was taken out of it, and the name is copied into `name` so the row still reads correctly
 * when the definition is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Cascades: a person's account being deleted takes their private files with it. This is
            // the one relation here where cascade is the safe choice rather than the lazy one.
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();

            $table->foreignId('report_definition_id')->nullable()->constrained()->nullOnDelete();

            // Copied, not derived. See the class note.
            $table->string('name');
            $table->string('entity_type', 64);
            $table->string('format', 8);

            // queued | running | ready | failed | expired. Text rather than an enum column so a
            // company on MySQL and a test on SQLite agree, which is the same choice every other
            // status column in this schema makes.
            $table->string('status', 16)->default('queued');

            // What the file contained, recorded whether or not the file still exists. `truncated`
            // matters most on an old row: a spreadsheet found in an inbox two years later is only
            // evidence if the trail can say the list was cut short (§59).
            $table->unsignedInteger('rows')->nullable();
            $table->boolean('truncated')->default(false);

            // Both filter sets, kept apart, for the reason ReportExporter's audit entry keeps them
            // apart: "what the report asks" and "what this person added on the day" are different
            // questions when two exports of one report disagree.
            $table->json('filters')->nullable();
            $table->json('ad_hoc_filters')->nullable();

            // Relative to the private disk, and generated — never built from company-entered text.
            // The filename a person sees on download is a separate, display-only column (§84).
            $table->string('path')->nullable();
            $table->string('filename');
            $table->unsignedBigInteger('bytes')->nullable();

            // Why it could not be produced, in words a screen can print. A queued export that fails
            // silently is a person waiting all afternoon for a notification that is never coming.
            $table->string('failure_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Null until the file exists. The sweep and the download route both read this; the
            // download checks it as well as the signature, because a signed URL's own expiry and
            // the file's retention are two different clocks and only one of them is ours.
            $table->timestamp('expires_at')->nullable();

            // Not a security control — the file is gone or it is not. It answers "was this ever
            // actually collected", which is the first question asked when a link is found in a
            // forwarded email.
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->timestamps();

            // The list one person sees of their own exports, newest first.
            $table->index(['organization_id', 'requested_by', 'created_at'], 'exports_owner_idx');

            // The sweep's query: everything past its date that still has bytes on disk.
            $table->index(['expires_at', 'status'], 'exports_retention_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
