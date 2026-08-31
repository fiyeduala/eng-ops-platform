<?php

declare(strict_types=1);

namespace App\Engines\Document;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;

/**
 * Works out what the next revision is called (§65).
 *
 * Every engineering organization has an opinion about this and none of them agree. Drawings go
 * A, B, C while in draft and 0, 1, 2 once issued for construction. Procedures go 1.0, 1.1, 2.0.
 * Some houses use P01, P02 for preliminaries. All of these are configuration on the CATEGORY, not
 * code, because one company runs several of them side by side.
 *
 * `revision_index` is the integer that makes this tractable. It is the sort key and the uniqueness
 * key; `revision` is the label people read. Sorting on the label is the bug this design exists to
 * avoid — "10" sorts before "9", and "B" sorts after "A" only by luck of encoding.
 *
 * THE DRAFT SERIES is the subtlety worth understanding. A category with a `draft_revision_prefix`
 * runs two parallel sequences: drafts (P01, P02...) and issued revisions (0, 1, 2...). A draft that
 * gets approved does NOT keep its draft label — it becomes the next issued revision. That is what
 * "issued" means on a controlled document, and a register showing P03 as the current approved
 * revision would be telling the reader something false about its status.
 */
class RevisionSequencer
{
    public const SCHEME_ALPHA = 'alpha';

    public const SCHEME_NUMERIC = 'numeric';

    public const SCHEME_DECIMAL = 'decimal';

    public const SCHEMES = [
        self::SCHEME_ALPHA => 'Letters — A, B, C',
        self::SCHEME_NUMERIC => 'Numbers — 0, 1, 2',
        self::SCHEME_DECIMAL => 'Decimal — 1.0, 1.1, 2.0',
    ];

    /**
     * The label and index for the next revision of a document.
     *
     * @param  bool  $draft  true while the revision is still in the draft series
     * @return array{revision:string,revision_index:int,is_draft_series:bool}
     */
    public function next(Document $document, bool $draft = true): array
    {
        $category = $document->category;
        $inDraftSeries = $draft && $category?->hasDraftSeries() === true;

        $index = $this->highestIndex($document, $inDraftSeries) + 1;

        return [
            'revision' => $inDraftSeries
                ? $this->draftLabel($category, $index)
                : $this->label($category?->revision_scheme ?? self::SCHEME_ALPHA, $index),
            'revision_index' => $this->storedIndex($index, $inDraftSeries),
            'is_draft_series' => $inDraftSeries,
        ];
    }

    /**
     * The label a draft takes when it is approved and joins the issued series.
     *
     * Called by DocumentVersionService at the moment a route completes, which is the only moment the
     * answer is knowable: another revision may have been issued while this one sat in review.
     *
     * @return array{revision:string,revision_index:int,is_draft_series:bool}
     */
    public function promote(Document $document): array
    {
        return $this->next($document, draft: false);
    }

    /** The label for a given position in a scheme. Position 1 is the first revision. */
    public function label(string $scheme, int $index): string
    {
        return match ($scheme) {
            self::SCHEME_NUMERIC => (string) ($index - 1),
            self::SCHEME_DECIMAL => $this->decimal($index),
            default => $this->alpha($index),
        };
    }

    // ------------------------------------------------------------------ schemes

    /**
     * A, B, C ... Z, AA, AB — spreadsheet-column lettering.
     *
     * Skipping I and O, which several drawing-office standards require because they are mistaken
     * for 1 and 0 on a printed title block, is deliberately NOT done here: it would make the
     * sequence unpredictable to anybody who did not know the rule, and companies that need it
     * should use an explicit numbering pattern instead.
     */
    private function alpha(int $index): string
    {
        $label = '';
        $n = max(1, $index);

        while ($n > 0) {
            $n--;
            $label = chr(65 + ($n % 26)).$label;
            $n = intdiv($n, 26);
        }

        return $label;
    }

    /** 1.0, 1.1 ... 1.9, 2.0 — minor revisions rolling into a major every tenth. */
    private function decimal(int $index): string
    {
        $position = max(1, $index) - 1;

        return intdiv($position, 10) + 1 .'.'.($position % 10);
    }

    private function draftLabel(?DocumentCategory $category, int $index): string
    {
        $prefix = $category?->draft_revision_prefix ?? 'P';

        return $prefix.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------ indices

    /**
     * Draft indices are stored offset by a large constant so the two series cannot collide.
     *
     * `document_versions` has a unique index on (document_id, revision_index), and drafts and issued
     * revisions genuinely are separate sequences that both start at 1. Offsetting is what lets one
     * unique constraint cover both, which in turn is what stops two simultaneous saves creating two
     * "revision 1"s — a database constraint rather than an application check, per §85.
     */
    private const DRAFT_OFFSET = 100000;

    private function storedIndex(int $index, bool $draft): int
    {
        return $draft ? self::DRAFT_OFFSET + $index : $index;
    }

    private function highestIndex(Document $document, bool $draft): int
    {
        $query = DocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->where('is_draft_series', $draft);

        $max = (int) $query->max('revision_index');

        if ($max === 0) {
            return 0;
        }

        return $draft ? $max - self::DRAFT_OFFSET : $max;
    }

    /** @return array<string,string> for a select on the category form */
    public static function schemeOptions(): array
    {
        return self::SCHEMES;
    }
}
