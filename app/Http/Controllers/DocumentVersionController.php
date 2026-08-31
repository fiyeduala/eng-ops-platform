<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Document\DocxExporter;
use App\Engines\Document\FileVault;
use App\Engines\Document\PdfRenderer;
use App\Http\Requests\DocumentVersionRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Template;
use App\Services\DocumentVersionService;
use App\Support\Enums\DocumentKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Revisions: the editor, the uploads, and the way documents get out of the system (§27, §67).
 *
 * This is the half of the document module that answers "replace Word, or work with it". A revision
 * can be AUTHORED HERE — TipTap in the browser, HTML in the database, nothing on disk — or UPLOADED,
 * in which case the file is the record and a .docx is additionally imported into editable content
 * when the register authors natively. Either way it comes back out as .docx or PDF. The round trip
 * is lossy and documented as such: what survives is structure, text, tables and basic formatting;
 * what does not is tracked changes, exact pagination and embedded objects. That is a real
 * limitation and the export screen says so rather than letting somebody discover it after sending
 * a contract out.
 *
 * NESTED BINDING IS CHECKED, NOT ASSUMED. Every method that takes both a document and a version
 * asserts the version belongs to that document and 404s otherwise. Laravel's scoped bindings would
 * do it, but doing it here makes the check visible in the file that depends on it — and this is
 * exactly the shape of §94's IDOR test: `/documents/1/versions/97` where 97 belongs to document 2.
 *
 * DOWNLOAD IS ITS OWN PERMISSION. `document.view` lets you read a controlled procedure on screen,
 * with its watermark and its revision banner; `document.download` lets you take the file away. Those
 * are different acts with different consequences, so DocumentPolicy answers them separately and
 * every route below re-asks with the record in hand. Nothing is served from a guessable URL and
 * nothing is public: files live on the private disk and are streamed by this controller or not at
 * all (§67).
 */
class DocumentVersionController extends Controller
{
    public function __construct(
        private readonly DocumentVersionService $versions,
        private readonly ActivityRecorder $activity,
        private readonly FileVault $vault,
        private readonly DocxExporter $docx,
        private readonly PdfRenderer $pdf,
    ) {}

    // ------------------------------------------------------------------ creating

    public function create(Request $request, Document $document): View
    {
        $this->authorize('createVersion', $document);

        $document->loadMissing(['category', 'currentVersion']);

        return view('documents.versions.create', [
            'document' => $document,
            'previous' => $document->currentVersion,
            'nextRevision' => $this->versions->previewRevision($document),
            'allowedExtensions' => $this->vault->allowedExtensions($document->category),
            'maxUploadMb' => $this->vault->maxUploadMb($document->category),
            'canAuthor' => $document->kind === DocumentKind::Native,
        ]);
    }

    /**
     * Start a new revision, authored or uploaded.
     *
     * Which of the two is decided by whether a file arrived, not by a posted flag. A flag would be
     * a second source of truth about the same fact, and the two would eventually disagree.
     */
    public function store(DocumentVersionRequest $request, Document $document): RedirectResponse
    {
        $this->authorize('createVersion', $document);

        $file = $request->file('file');
        $attributes = $request->versionAttributes();

        try {
            $version = $file !== null
                ? $this->versions->createFromUpload($document, $file, $attributes, $request->user())
                : $this->versions->createNative($document, $attributes, $request->user());
        } catch (\DomainException $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        return redirect()->route(
            $version->hasFile() && ! $version->hasContent()
                ? 'documents.show'
                : 'documents.versions.edit',
            $version->hasFile() && ! $version->hasContent() ? $document : [$document, $version],
        )->with('status', 'Revision '.$version->revision.' has been created.');
    }

    // -------------------------------------------------------------------- editor

    public function edit(Request $request, Document $document, DocumentVersion $version): View
    {
        $this->assertBelongs($document, $version);

        $this->authorize('update', $version);

        $document->loadMissing('category');

        return view('documents.versions.edit', [
            'document' => $document,
            'version' => $version,

            // The editor holds the lock for the whole session. Shown rather than taken silently:
            // the person needs to know they are holding something other people are waiting for.
            'lockHeld' => $document->isLockedBy($request->user()),
            'lockedByOther' => $document->isLockedByAnother($request->user()),
            'canCheckOut' => $request->user()->can('checkOut', $document),
        ]);
    }

    /**
     * Save the editor's content.
     *
     * ANSWERS JSON TO THE AUTOSAVE AND A REDIRECT TO THE SAVE BUTTON, from one method, because they
     * are the same write and splitting them would eventually let one path skip a check the other
     * makes.
     *
     * The service refuses to touch an approved revision regardless of what this controller allows —
     * that rule is about the ROW, not about the person, and belongs where the row is written.
     */
    public function update(
        DocumentVersionRequest $request,
        Document $document,
        DocumentVersion $version,
    ): RedirectResponse|JsonResponse {
        $this->assertBelongs($document, $version);

        $this->authorize('update', $version);

        try {
            $this->versions->updateContent($version, $request->versionAttributes(), $request->user());
        } catch (\DomainException $e) {
            return $request->wantsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['content_html' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'saved_at' => $version->updated_at?->toIso8601String(),
                'word_count' => $version->word_count,
            ]);
        }

        return redirect()->route('documents.show', $document)
            ->with('status', 'Revision '.$version->revision.' has been saved.');
    }

    /** Read-only render of one revision, watermarked when it is not the approved one. */
    public function show(Request $request, Document $document, DocumentVersion $version): View
    {
        $this->assertBelongs($document, $version);

        $this->authorize('view', $version);

        $version->loadMissing(['author:id,name', 'approver:id,name', 'status', 'signatures.user:id,name']);

        return view('documents.versions.show', [
            'document' => $document,
            'version' => $version,
            'canDownload' => $request->user()->can('download', $document),
            'canPrint' => $request->user()->can('print', $document),
            'canEdit' => $request->user()->can('update', $version),
        ]);
    }

    /**
     * Make a revision the one the document's number means.
     *
     * Superseding is a document-control act, not an edit, so it takes `document.manage` through
     * DocumentPolicy rather than riding on `update`.
     */
    public function makeCurrent(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        $this->assertBelongs($document, $version);

        $this->authorize('manage', $document);

        $this->versions->makeCurrent($version);

        $this->activity->record(
            $document,
            'revision_current',
            $request->user()->name.' made Rev '.$version->revision.' the current revision.',
            ['revision' => $version->revision],
            $request->user(),
            $document->project_id !== null ? (int) $document->project_id : null,
        );

        return back()->with('status', 'Rev '.$version->revision.' is now the current revision.');
    }

    public function destroy(Request $request, Document $document, DocumentVersion $version): RedirectResponse
    {
        $this->assertBelongs($document, $version);

        $this->authorize('delete', $version);

        $label = $version->revision;

        $this->vault->delete($version->file_path, $version->file_disk ?? FileVault::DISK);

        $version->delete();

        $this->activity->record(
            $document,
            'revision_deleted',
            $request->user()->name.' deleted draft revision '.$label.'.',
            ['revision' => $label],
            $request->user(),
        );

        return redirect()->route('documents.show', $document)
            ->with('status', 'Draft revision '.$label.' has been deleted.');
    }

    // ------------------------------------------------------------------ getting it out

    /**
     * Stream the stored file.
     *
     * Four things happen before a byte moves: the version is confirmed to belong to this document,
     * DocumentPolicy::download() is asked with the record in hand, the row is confirmed to have a
     * file at all, and the file is confirmed to exist on the private disk. A missing file is a 404
     * rather than a 500 — the storage path is not something a user should ever see (§67).
     */
    public function download(Request $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        $this->assertBelongs($document, $version);

        $this->authorize('download', $document);

        abort_unless($version->hasFile(), 404);

        $disk = Storage::disk($version->file_disk ?? FileVault::DISK);

        abort_unless($disk->exists((string) $version->file_path), 404);

        $this->recordExport($request, $document, $version, 'downloaded', 'file');

        return $disk->download(
            (string) $version->file_path,
            $this->vault->downloadName(
                $document,
                $version->revision,
                pathinfo((string) $version->original_name, PATHINFO_EXTENSION) ?: 'bin',
            ),
        );
    }

    /**
     * Export authored content as .docx — the "integrate with Word" half of the promise.
     *
     * Generated on demand rather than stored. A cached export would go stale the moment the
     * revision was edited, and a stale .docx that looks official is worse than no export at all.
     */
    public function exportDocx(Request $request, Document $document, DocumentVersion $version): Response
    {
        $this->assertBelongs($document, $version);

        $this->authorize('download', $document);

        abort_unless($version->hasContent(), 404);

        $this->recordExport($request, $document, $version, 'exported', 'docx');

        return response($this->docx->render($version, $this->templateFor($document)), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$this->docx->filename($document, $version).'"',
        ]);
    }

    /**
     * Export as PDF.
     *
     * Gated on `print` rather than `download`: a PDF is the form a controlled document is normally
     * circulated in, and PdfRenderer stamps an unapproved revision UNCONTROLLED COPY precisely
     * because printed copies outlive the screen they came from.
     */
    public function exportPdf(Request $request, Document $document, DocumentVersion $version): Response
    {
        $this->assertBelongs($document, $version);

        $this->authorize('print', $document);

        abort_unless($version->hasContent(), 404);

        $this->recordExport($request, $document, $version, 'printed', 'pdf');

        return response($this->pdf->renderVersion($version, $this->templateFor($document)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->pdf->filename($document, $version).'"',
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The nested-binding check, and the §94 IDOR defence for this module.
     *
     * The tenant scope already makes another organization's revision invisible; this makes another
     * DOCUMENT's revision a 404 too. Without it, `/documents/1/versions/97` would authorize against
     * document 1 and then serve revision 97 of something else entirely.
     */
    private function assertBelongs(Document $document, DocumentVersion $version): void
    {
        abort_unless((int) $version->document_id === (int) $document->getKey(), 404);
    }

    /** The house style this register renders in, if it has one. */
    private function templateFor(Document $document): ?Template
    {
        return Template::query()->active()->ofKind('document')
            ->forCategory($document->document_category_id)
            ->orderByDesc('is_default')
            ->first();
    }

    /**
     * Every export leaves a trace.
     *
     * Who took a copy of a controlled document, when, and in what form is exactly the question a
     * document-control audit asks, and it cannot be reconstructed after the fact (§59).
     */
    private function recordExport(
        Request $request,
        Document $document,
        DocumentVersion $version,
        string $verb,
        string $format,
    ): void {
        $this->activity->record(
            $document,
            $verb,
            $request->user()->name.' '.$verb.' Rev '.$version->revision.' ('.mb_strtoupper($format).').',
            ['revision' => $version->revision, 'format' => $format],
            $request->user(),
            $document->project_id !== null ? (int) $document->project_id : null,
        );
    }
}
