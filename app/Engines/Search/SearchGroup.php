<?php

declare(strict_types=1);

namespace App\Engines\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * What one register had to say about one search term.
 *
 * `more` rather than a total, and deliberately: knowing there are exactly 1,204 matching purchase
 * orders is worth one extra COUNT per register on every keystroke-sized request, and it is not
 * worth it. The sweep asks each register for one row more than it will show and reports whether it
 * got one — which is all the results page needs to decide whether to offer "see all of these".
 * The narrowed page, which is one register and one query, prints a real total.
 */
final readonly class SearchGroup
{
    /**
     * @param  Collection<int,Model>  $records
     */
    public function __construct(
        public string $entityType,
        public string $label,
        public Collection $records,
        public bool $more,
    ) {}

    public function isEmpty(): bool
    {
        return $this->records->isEmpty();
    }
}
