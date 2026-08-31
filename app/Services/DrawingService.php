<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\Drawing;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The drawing register (§26).
 *
 * A THIN SERVICE ON PURPOSE. Almost everything interesting about a drawing — its revisions, its
 * file, its signatures, who may download it — belongs to the document module, and this service's
 * job is to stay out of that. What it owns is the title block: number, sheet, scale, revision,
 * issue purpose, and the pointer at the controlled document.
 *
 * `revise()` is the one operation with judgement in it. Bumping a drawing to the next revision is
 * not an edit of the current one: the register line records "Rev C, issued for construction on the
 * 4th", and quietly overwriting that with Rev D loses the only fact anybody will want in two years.
 * So it moves the line forward AND writes what it moved from into the timeline, which is the
 * cheapest honest version of revision history for a table that deliberately stores no files.
 */
class DrawingService extends RegisterService
{
    protected function entityType(): string
    {
        return 'drawing';
    }

    protected function modelClass(): string
    {
        return Drawing::class;
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'document_id'];
    }

    /**
     * Drawing numbers are the project's, not ours.
     *
     * Every other register in this phase numbers itself, and should. A drawing number is different
     * in kind: it encodes the project, the area, the discipline and the sheet in a convention
     * agreed with the client before this platform was installed, and it is frequently issued BY the
     * client. Generating one and refusing the real one would produce a register nobody can look a
     * drawing up in — the single thing a drawing register is for.
     *
     * The generator still supplies the suggestion the form opens with, so a company with no
     * convention gets DWG-00001 without typing anything.
     */
    protected function allowsSuppliedNumber(): bool
    {
        return true;
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Drawing $record */
        $sheet = $record->sheetLabel();

        return $actor->name.' added '.$this->label($record).' to the drawing register'
            .($sheet !== null ? ' ('.$sheet.')' : '').'.';
    }

    /**
     * Move the register line to the next revision.
     *
     * The previous revision and purpose go into the timeline rather than into a history table. That
     * is a deliberate limit and worth naming: this register keeps the CURRENT title block plus an
     * audited trail of how it got there, not a queryable row per revision. A company that needs to
     * report across historical revisions keeps the drawings as documents, where DocumentVersion
     * does exactly that job.
     */
    public function revise(Drawing $drawing, string $revision, User $actor, ?string $purpose = null, ?string $date = null): Drawing
    {
        $from = $drawing->revision;
        $fromPurpose = $drawing->purpose;

        return DB::transaction(function () use ($drawing, $revision, $actor, $purpose, $date, $from, $fromPurpose) {
            $drawing->revision = $revision;
            $drawing->revision_date = $date ?? now()->toDateString();

            if ($purpose !== null) {
                $drawing->purpose = $purpose;
            }

            $drawing->save();

            $this->activity->record(
                $drawing,
                'revised',
                $actor->name.' revised '.$drawing->number.' from '
                    .($from ?? 'no revision').' to '.$revision
                    .($purpose !== null && $purpose !== $fromPurpose ? ', issued as '.$purpose : '').'.',
                array_filter([
                    'from_revision' => $from,
                    'to_revision' => $revision,
                    'from_purpose' => $fromPurpose,
                    'to_purpose' => $purpose,
                ], fn ($v) => $v !== null),
                $actor,
                $this->projectIdOf($drawing),
            );

            return $drawing;
        });
    }

    /**
     * Attach the controlled file.
     *
     * Two checks, and the tenant one is explicit rather than trusted to the global scope: the scope
     * protects queries, this protects a caller holding a model, which is what a posted id becomes
     * after route binding (§84, §75). Whether the actor may READ the document is decided by the
     * document's own policy at the picker and again at download — attaching does not grant it.
     */
    public function attachDocument(Drawing $drawing, ?Document $document, User $actor): Drawing
    {
        // Null means "unlink". Somebody attaches the wrong file eventually, and a register that
        // could only ever add a link would leave the correction to a database console. The removal
        // is recorded, so the timeline still shows that a file was once pointed at.
        if ($document === null) {
            return $this->detachDocument($drawing, $actor);
        }

        if ((int) $document->organization_id !== (int) $drawing->organization_id) {
            throw new \DomainException('That document does not belong to this organization.');
        }

        $previous = $drawing->document_id;

        $drawing->document_id = $document->getKey();
        $drawing->save();

        $this->activity->record(
            $drawing,
            'document_linked',
            $actor->name.($previous === null ? ' attached ' : ' replaced the file on ')
                .$this->label($drawing).' with '.$document->displayName().'.',
            array_filter(['document_id' => $document->getKey(), 'previous_document_id' => $previous]),
            $actor,
            $this->projectIdOf($drawing),
        );

        return $drawing;
    }

    /** Break the link to the file. The register line survives; only the pointer goes. */
    private function detachDocument(Drawing $drawing, User $actor): Drawing
    {
        if ($drawing->document_id === null) {
            return $drawing;
        }

        $previous = (int) $drawing->document_id;

        $drawing->document_id = null;
        $drawing->save();

        $this->activity->record(
            $drawing,
            'document_unlinked',
            $actor->name.' removed the file from '.$this->label($drawing).'.',
            ['previous_document_id' => $previous],
            $actor,
            $this->projectIdOf($drawing),
        );

        return $drawing;
    }
}
