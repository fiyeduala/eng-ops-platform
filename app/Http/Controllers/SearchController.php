<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Engines\Search\RecordSearch;
use App\Support\SearchCatalogue;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One box (§61).
 *
 * THE WHOLE POINT OF THIS SCREEN IS THAT IT DECIDES NOTHING. It reads two strings off the query
 * string, hands them to RecordSearch, and renders what comes back. Which registers are covered,
 * what a term matches and who may see a row are settled elsewhere and deliberately not re-asked
 * here — a controller that "helpfully" widened any of the three would be the second
 * implementation docs/05's search-parity requirement exists to prevent.
 *
 * THE ONE JUDGEMENT IT DOES MAKE is what a refused register looks like. Narrowing to a register
 * the reader holds no permission for is a 404, not an empty list, because an empty list and a
 * forbidden one are indistinguishable to the person reading them and only one of them is true
 * (§84, §94). A register they cannot read is absent from the overview for the same reason, which
 * is decided one layer down.
 */
class SearchController extends Controller
{
    public function __construct(
        private readonly RecordSearch $search,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $term = (string) $request->query('q', '');
        $usable = RecordSearch::usable($term);

        $entityType = trim((string) $request->query('type', ''));

        // An unsearchable type is a typed URL rather than a link from this page. It is not found
        // rather than silently ignored, because quietly widening somebody's search to everything
        // is a worse answer than telling them the address is wrong.
        if ($entityType !== '') {
            abort_unless(SearchCatalogue::searchable($entityType), 404);
        }

        $records = null;
        $groups = [];

        if ($usable && $entityType !== '') {
            $records = $this->search->within($entityType, $term, $user);

            // Null here can only mean the register was refused: the term is already known usable.
            abort_if($records === null, 404);
        } elseif ($usable) {
            $groups = $this->search->across($term, $user);
        }

        return view('search.index', [
            'term' => trim($term),
            'usable' => $usable,
            'entityType' => $entityType === '' ? null : $entityType,
            'entityLabel' => $entityType === '' ? null : SearchCatalogue::labelFor($entityType),
            'groups' => $groups,
            'records' => $records,
            'minimum' => RecordSearch::MIN_TERM,
        ]);
    }
}
