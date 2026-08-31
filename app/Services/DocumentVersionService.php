<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Document\ContentSanitizer;
use App\Engines\Document\DocxImporter;
use App\Engines\Document\FileVault;
use App\Engines\Document\RevisionSequencer;
use App\Events\DocumentApproved;
use App\Events\DocumentRevisionRequested;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Support\Enums\DocumentKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * THE ONLY WRITER OF A DOCUMENT REVISION.
 *
 * Everything about revisions that can go wrong goes wrong through a second writer, so there is only
 * one. Controllers, the workflow engine, the importer and the seeders all come through here.
 *
 * FOUR INVARIANTS, held by this class and asserted by DocumentVersionTest:
 *
 *   1. EXACTLY ONE CURRENT REVISION. `is_current` on one row, `documents.current_version_id`
 *      pointing at that same row. Both are written together, in one transaction, by one method.
 *      They are denormalised twins and the only defence against them disagreeing is that nothing
 *      else may touch either.
 *
 *   2. AN APPROVED REVISION IS IMMUTABLE. Its content, its file and its label never change again.
 *      Editing an approved revision would mean the thing somebody signed is not the thing on file,
 *      which destroys the entire value of document control. New work makes a NEW revision.
 *
 *   3. A REVISION IS NATIVE OR FILE-BASED, NEVER BOTH FOR ITS CONTENT. A .docx import is the one
 *      case that carries both, and the file there is the ARCHIVAL ORIGINAL, not a second source of
 *      truth: `documents.kind` says which one is authoritative and this class never lets the two
 *      disagree about that.
 *
 *   4. THE DRAFT SERIES IS NOT THE ISSUED SERIES. A draft approved as P03 becomes revision B. The
 *      label changes at approval time and only then, because until then nobody knows what number is
 *      free — another revision may have been issued while this one sat in review.
 *
 * Authorization is NOT here. Callers run DocumentPolicy first. A service that also authorizes ends
 * up authorizing differently from the policy, and the difference is always a hole.
 */
class DocumentVersionService
{
    public function __construct(
        private readonly RevisionSequencer $sequencer,
        private readonly ContentSanitizer $sanitizer,
        private readonly FileVault $vault,
        private readonly DocxImporter $importer,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
    ) {}

    // ================================================================== creating

    /**
     * What the next revision will be called, without creating it.
     *
     * The "New revision" form shows this so the author knows whether they are about to raise Rev C
     * or draft P02 before they commit to it. Read-only by construction — RevisionSequencer::next()
     * derives the label from what already exists rather than consuming a counter — so calling this
     * and then abandoning the form leaves no gap in the series.
     */
    public function previewRevision(Document $document): string
    {
        return $this->sequencer->next($document)['revision'];
    }

    /**
     * Start a new authored revision.
     *
     * `derived_from_version_id` carries the lineage, and `content_html` is copied from the previous
     * revision unless the caller supplies its own. That copy is the whole ergonomics of revising a
     * controlled document: Rev C starts as Rev B and you change the three paragraphs that moved, in
     * this system, rather than downloading, editing and re-uploading.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function createNative(Document $document, array $attributes, User $actor): DocumentVersion
    {
        return DB::transaction(function () use ($document, $attributes, $actor) {
            $previous = $document->currentVersion;

            $html = array_key_exists('content_html', $attributes)
                ? $this->sanitizer->clean($attributes['content_html'])
                : $previous?->content_html;

            $version = $this->newVersion($document, $actor, $attributes);
            $version->content_html = $html;
            $version->content_json = $attributes['content_json'] ?? null;
            $version->word_count = $this->sanitizer->wordCount($html);
            $version->derived_from_version_id = $previous?->getKey();
            $version->save();

            $this->recordCreation($document, $version, $actor, 'was drafted');

            return $version;
        });
    }

    /**
     * Start a new revision from an uploaded file.
     *
     * A .docx is ALSO imported into editable content when the category authors natively, and the
     * original file is kept regardless. Keeping it is not redundancy — the import is lossy by
     * documented design, and the original is what somebody falls back to when the conversion ate
     * their equation.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function createFromUpload(
        Document $document,
        UploadedFile $file,
        array $attributes,
        User $actor,
    ): DocumentVersion {
        // Deliberately OUTSIDE the transaction. Storage is not transactional, so a file written
        // inside a transaction that later rolls back is an orphan on disk with no row pointing at
        // it. Written first, and cleaned up by hand if the database work then fails.
        $stored = $this->vault->store($file, $document);

        try {
            return DB::transaction(function () use ($document, $file, $stored, $attributes, $actor) {
                $version = $this->newVersion($document, $actor, $attributes);

                $version->file_disk = $stored['disk'];
                $version->file_path = $stored['path'];
                $version->file_hash = $stored['hash'];
                $version->file_size = $stored['size'];
                $version->mime_type = $stored['mime'];
                $version->original_name = $stored['original_name'];
                $version->derived_from_version_id = $document->currentVersion?->getKey();

                if ($document->isNative() && $this->importer->isDocx($file)) {
                    $html = $this->sanitizer->clean($this->importer->toHtml($file->getRealPath()));
                    $version->content_html = $html;
                    $version->word_count = $this->sanitizer->wordCount($html);
                }

                $version->save();

                $this->recordCreation($document, $version, $actor, 'was uploaded');

                return $version;
            });
        } catch (\Throwable $e) {
            $this->vault->delete($stored['path'], $stored['disk']);

            throw $e;
        }
    }

    // ================================================================== editing

    /**
     * Save changes to a DRAFT revision, in place.
     *
     * Refuses on an approved revision — invariant 2, enforced here rather than trusted to the policy
     * because a policy answers "may this person edit documents" and this answers "may this ROW be
     * edited at all", and those are different questions with different right answers.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function updateContent(DocumentVersion $version, array $attributes, User $actor): DocumentVersion
    {
        if ($version->isApproved()) {
            throw new \DomainException(
                'Revision '.$version->revision.' has been approved and cannot be changed. Create a new revision instead.'
            );
        }

        return DB::transaction(function () use ($version, $attributes) {
            if (array_key_exists('content_html', $attributes)) {
                $html = $this->sanitizer->clean($attributes['content_html']);
                $version->content_html = $html;
                $version->word_count = $this->sanitizer->wordCount($html);
            }

            if (array_key_exists('content_json', $attributes)) {
                $version->content_json = $attributes['content_json'];
            }

            if (array_key_exists('change_summary', $attributes)) {
                $version->change_summary = $attributes['change_summary'];
            }

            $version->save();

            // Not recorded on the timeline. An autosaving editor fires this every few seconds, and a
            // timeline reporting two hundred "edited the content" entries for one afternoon's work
            // is a timeline nobody reads. The audit log still captures the change (§59); the
            // timeline is for events a person would recount out loud.
            return $version;
        });
    }

    // ================================================================== lifecycle

    /**
     * Make a revision the one the document's number means.
     *
     * The single place invariant 1 is maintained. Note the order: clear every other row's flag
     * first, then set this one, then point the document at it. Any other order leaves a window in
     * which two rows are current, and on a register that half-refreshes during it, somebody reads
     * the wrong revision.
     */
    public function makeCurrent(DocumentVersion $version): DocumentVersion
    {
        return DB::transaction(function () use ($version) {
            $document = $version->document;

            DocumentVersion::query()
                ->where('document_id', $document->getKey())
                ->where('id', '!=', $version->getKey())
                ->where('is_current', true)
                ->update(['is_current' => false, 'superseded_at' => now(), 'updated_at' => now()]);

            $version->forceFill(['is_current' => true, 'superseded_at' => null])->save();

            $document->forceFill(['current_version_id' => $version->getKey()])->save();

            return $version;
        });
    }

    /**
     * A revision becomes the issued one. THE ONLY PLACE THAT MAY SAY SO.
     *
     * There are two ways a revision gets approved — a workflow route completing, and a register with
     * no route issuing on submission — and for a while there were two implementations. They drifted,
     * as duplicated lifecycle code always does: the second one stamped `approved_at` and moved the
     * pointer but never promoted the draft label, so a document in an unrouted register was issued,
     * current, and still called "P01". A register that shows a preliminary revision number against
     * an approved document is telling the reader the opposite of the truth.
     *
     * So the sequence lives here once, and both callers pass in only what actually differs between
     * them: who approved it, and what the timeline should say about why.
     *
     * The order matters. Promote the label BEFORE stamping the approval, because the promoted
     * number is what the activity entry and the notification quote; move the pointer after, because
     * `makeCurrent` supersedes whatever it displaces and that must not happen if the save above
     * failed. All of it is inside one transaction: a half-approved revision is worse than none.
     */
    public function approveRevision(
        Document $document,
        DocumentVersion $version,
        ?User $approver,
        string $narrative,
        bool $routed = true,
    ): DocumentVersion {
        return DB::transaction(function () use ($document, $version, $approver, $narrative, $routed) {
            if ($version->is_draft_series) {
                $promoted = $this->sequencer->promote($document);
                $version->revision = $promoted['revision'];
                $version->revision_index = $promoted['revision_index'];
                $version->is_draft_series = false;
            }

            $version->approved_by = $approver?->getKey();
            $version->approved_at = now();
            $version->status_id = $this->statusId('document', 'approved') ?? $version->status_id;
            $version->save();

            $this->makeCurrent($version);

            $this->moveDocumentStatus($document, 'approved', $approver);

            $this->activity->record(
                $document,
                'approved',
                str_replace(':revision', (string) $version->revision, $narrative),
                ['revision' => $version->revision, 'version_id' => $version->getKey(), 'routed' => $routed],
                $approver,
            );

            DocumentApproved::dispatch($document, $version, $approver?->getKey());

            return $version;
        });
    }

    /**
     * The approval route completed — called by Document::onWorkflowApproved inside the engine's
     * transaction.
     */
    public function markApproved(Document $document, WorkflowInstance $instance): void
    {
        $version = $this->versionUnderReview($document, $instance);

        if ($version === null) {
            return;
        }

        $this->approveRevision(
            $document,
            $version,
            $this->finalApprover($instance),
            'Revision :revision was approved and is now the current revision.',
        );
    }

    /** The route ended in a rejection: the revision stays a draft and is marked as rejected. */
    public function markRejected(Document $document, WorkflowInstance $instance): void
    {
        $version = $this->versionUnderReview($document, $instance);
        $actor = $this->finalApprover($instance);

        if ($version !== null) {
            $version->status_id = $this->statusId('document', 'rejected') ?? $version->status_id;
            $version->save();
        }

        $this->moveDocumentStatus($document, 'rejected', $actor);

        DocumentRevisionRequested::dispatch($document, $instance->outcome_reason, $actor?->getKey());
    }

    /** Changes were asked for: back to draft, so the author can pick it straight back up. */
    public function markRevisionRequested(Document $document, WorkflowInstance $instance): void
    {
        $version = $this->versionUnderReview($document, $instance);
        $actor = $this->finalApprover($instance);

        if ($version !== null) {
            $version->status_id = $this->statusId('document', 'draft') ?? $version->status_id;
            $version->save();
        }

        $this->moveDocumentStatus($document, 'draft', $actor);

        DocumentRevisionRequested::dispatch($document, $instance->outcome_reason, $actor?->getKey());
    }

    // ================================================================== internals

    /**
     * A blank revision with its label, sequence and authorship already correct.
     *
     * `organization_id` is taken from the DOCUMENT, never from the caller's attributes. A validator
     * that let an organization_id through would otherwise be one careless form away from writing a
     * revision into another company (§74, §75).
     *
     * @param  array<string,mixed>  $attributes
     */
    private function newVersion(Document $document, User $actor, array $attributes): DocumentVersion
    {
        $draft = ! ($attributes['issued'] ?? false);
        $next = $this->sequencer->next($document, draft: $draft);

        $version = new DocumentVersion;
        $version->organization_id = $document->organization_id;
        $version->document_id = $document->getKey();
        $version->revision = $attributes['revision'] ?? $next['revision'];
        $version->revision_index = $next['revision_index'];
        $version->is_draft_series = $next['is_draft_series'];
        $version->author_id = $attributes['author_id'] ?? $actor->getKey();
        $version->change_summary = $attributes['change_summary'] ?? null;
        $version->status_id = $this->statusId('document', 'draft');
        $version->created_by = $actor->getKey();
        $version->is_current = false;

        return $version;
    }

    /**
     * The revision a running route is about.
     *
     * The latest non-approved revision, because that is the one that was submitted. Deliberately not
     * `currentVersion` — the current revision is the last APPROVED one, which is exactly the row
     * that must not be touched by an approval of something newer.
     */
    private function versionUnderReview(Document $document, WorkflowInstance $instance): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->whereNull('approved_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Whoever recorded the decision that closed the last stage. */
    private function finalApprover(WorkflowInstance $instance): ?User
    {
        // `reorder()` first: stageInstances() carries its own ORDER BY, which would otherwise win
        // and hand back the FIRST approver — so a two-stage route would record the technical
        // reviewer as the person who approved the document rather than the director who endorsed it.
        $stage = $instance->stageInstances()
            ->where('attempt', $instance->attempt)
            ->whereNotNull('acted_by')
            ->reorder()
            ->orderByDesc('sequence')
            ->first();

        return $stage?->actor;
    }

    /**
     * Move the document's status, tolerating a state machine that forbids the move.
     *
     * A tenant may have edited its own document transitions — that is the point of §63 — and a
     * refused transition must not roll back an approval that has already happened. The status is a
     * label on the record; the approval is the fact.
     */
    private function moveDocumentStatus(Document $document, string $key, ?User $actor): void
    {
        if ($actor === null) {
            return;
        }

        $status = $this->statuses->statusesFor('document')->firstWhere('key', $key);

        if ($status === null) {
            return;
        }

        try {
            $this->statuses->transition($document, $status, $actor, 'Moved by the approval route.');
        } catch (\DomainException) {
            // The tenant's own transition table says this move is not allowed. Leave the status
            // alone rather than forcing it; the timeline and the workflow instance both record what
            // actually happened.
            $document->forceFill(['status_id' => $status->id])->save();
        }
    }

    private function statusId(string $entityType, string $key): ?int
    {
        return $this->statuses->statusesFor($entityType)->firstWhere('key', $key)?->id;
    }

    private function recordCreation(Document $document, DocumentVersion $version, User $actor, string $verb): void
    {
        $this->activity->record(
            $document,
            'revision_created',
            'Revision '.$version->revision.' '.$verb.' by '.$actor->name.'.',
            array_filter([
                'revision' => $version->revision,
                'version_id' => $version->getKey(),
                'kind' => $document->kind instanceof DocumentKind ? $document->kind->value : null,
            ]),
            $actor,
        );
    }
}
