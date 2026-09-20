<?php

declare(strict_types=1);

namespace App\Engines\Report;

use Carbon\CarbonInterface;

/**
 * One report's rows, and everything needed to read them honestly.
 *
 * THE SAME THREE HONESTY FIELDS KpiResult CARRIES, for the same reason: an empty table has to say
 * whether it means "nothing matched" or "this could not be run", because the first is information
 * and the second is a fault, and they look identical on screen.
 *
 * PLUS ONE MORE THAT ONLY A LIST NEEDS — `truncated`. A number cannot be partially true; a list can,
 * and a list that quietly stopped at five thousand rows is the single most dangerous thing in this
 * file. Somebody reconciles against it, the totals disagree with the finance system by exactly the
 * rows that were dropped, and nothing on the page ever said so. Every renderer — screen, CSV and
 * PDF alike — is obliged to print this, and the export audit entry records it too (§59).
 *
 * THE ROWS ARE THE VIEWER'S. `ranFor` travels with them for the reason `countedFor` travels with a
 * KPI figure: a set of rows detached from whose visibility produced it is a set of rows somebody
 * will eventually hand to the wrong reader (§61).
 */
final readonly class ReportResult
{
    /**
     * @param  array<string,string>  $columns  field => heading, in print order
     * @param  array<int,array{label:string|null,rows:array<int,array<string,string>>}>  $groups
     *                                                                                            one entry with a null label where the report is not grouped
     * @param  int  $total  rows returned — never an estimate of rows that exist
     * @param  bool  $truncated  true where the ceiling cut the list short
     * @param  bool  $available  false where the report could not be run at all
     * @param  string|null  $reason  why not, in words a screen can print
     * @param  int|null  $ranFor  the user whose visibility produced these rows
     */
    public function __construct(
        public array $columns,
        public array $groups,
        public int $total = 0,
        public bool $truncated = false,
        public bool $available = true,
        public ?string $reason = null,
        public ?int $ranFor = null,
        public ?CarbonInterface $ranAt = null,
    ) {}

    public static function unavailable(string $reason, ?int $ranFor = null): self
    {
        return new self(columns: [], groups: [], available: false, reason: $reason, ranFor: $ranFor);
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function isGrouped(): bool
    {
        return count($this->groups) > 1
            || (count($this->groups) === 1 && $this->groups[0]['label'] !== null);
    }

    /**
     * Every row, flattened — for the renderers that do not draw group headings.
     *
     * @return array<int,array<string,string>>
     */
    public function rows(): array
    {
        $rows = [];

        foreach ($this->groups as $group) {
            foreach ($group['rows'] as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The sentence a reader needs to see when the list was cut short.
     *
     * Returned rather than left to each renderer to word, so the screen, the CSV and the PDF all
     * say the same thing. A CSV that omitted it would be the worst of the three: it is the copy
     * that gets opened in a spreadsheet, totalled, and believed.
     */
    public function truncationNotice(): ?string
    {
        if (! $this->truncated) {
            return null;
        }

        return 'This report stopped at '.number_format($this->total).' rows. There are more that match — '
            .'narrow it with a filter, or raise the row limit in its settings.';
    }
}
