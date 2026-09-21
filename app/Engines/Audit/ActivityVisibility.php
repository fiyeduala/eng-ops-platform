<?php

declare(strict_types=1);

namespace App\Engines\Audit;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * A timeline, filtered to the records its reader may actually open (§60).
 *
 * AN ACTIVITY ROW IS A SENTENCE ABOUT ANOTHER RECORD, and that is the whole problem. "Tunde closed
 * Incident INC-0031 — Fall from scaffold, Piping" is a disclosure of an incident's existence, its
 * reference, its nature and its department, written in plain English and sitting on a page that
 * looks like harmless chrome. The record itself is behind `hse_incident.view`; the sentence about
 * it was, until this class, behind nothing at all. So a timeline entry inherits the visibility of
 * its SUBJECT — not of the person who caused it, not of the project it is filed against.
 *
 * TWO GATES, AND THE SECOND IS THE ONE THAT MATTERS. Holding `document.view` is not seeing every
 * document, so it is not enough to check the permission and keep the row: the subject ids are put
 * back through `visibleTo()`, which is the same call the register's own index page makes. A
 * department head therefore sees the documents of their department appear on the timeline and the
 * ones next door not appear, with no branch here saying so.
 *
 * WHAT IS DROPPED RATHER THAN SHOWN, in every ambiguous case:
 *
 *   - a subject whose morph alias no longer resolves to a class — nothing can be proven about it
 *   - a subject whose model cannot be visibility-filtered at all — same reason
 *   - a subject that has since been deleted, because `whereKey` will not find it
 *
 * All three are silent omissions, which is a real cost: a reader cannot tell a filtered timeline
 * from a quiet one. That is the right trade here — the alternative is printing a sentence about a
 * record whose visibility nobody could establish, and "we could not check" must never read as "yes".
 *
 * ON QUERY COUNT (§89). One query per distinct register on the page, not one per row: eight
 * entries about eight documents cost a single `whereIn`. A page mixing a dozen registers costs a
 * dozen small keyed lookups, which is the shape of the data rather than an N+1 — and the callers
 * fetch a wider slice and trim afterwards, so a filtered timeline still fills its eight rows
 * instead of showing three and looking broken.
 */
class ActivityVisibility
{
    /**
     * Keep only the entries whose subject this person may see.
     *
     * @param  Collection<int,ActivityLog>  $entries
     * @return Collection<int,ActivityLog>
     */
    public function filter(Collection $entries, ?User $viewer): Collection
    {
        if ($viewer === null || $entries->isEmpty()) {
            return $entries->take(0);
        }

        $allowed = [];

        foreach ($entries->groupBy('subject_type') as $type => $rows) {
            $allowed[(string) $type] = $this->visibleIds(
                (string) $type,
                $rows->pluck('subject_id')->filter()->unique()->map(fn ($id): int => (int) $id)->all(),
                $viewer,
            );
        }

        return $entries->filter(function (ActivityLog $entry) use ($allowed): bool {
            $type = (string) $entry->subject_type;

            return $entry->subject_id !== null
                && in_array((int) $entry->subject_id, $allowed[$type] ?? [], true);
        })->values();
    }

    /**
     * Which of these ids, on this register, this person may see.
     *
     * @param  array<int,int>  $ids
     * @return array<int,int>
     */
    private function visibleIds(string $type, array $ids, User $viewer): array
    {
        if ($ids === []) {
            return [];
        }

        $class = Relation::getMorphedModel($type) ?? (class_exists($type) ? $type : null);

        if ($class === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return [];
        }

        // A register with no visibility scope cannot answer "may this person see row 41", and a
        // register that cannot answer is not one to guess about.
        if (! method_exists($class, 'scopeVisibleTo')) {
            return [];
        }

        $permission = $class::defaultViewPermission();

        // The cheap gate first. Somebody without the register's read permission at all is spared a
        // query, and — more to the point — a scope compiler asked about a permission nobody holds
        // is being asked the wrong question.
        if (! $viewer->hasPermission($permission)) {
            return [];
        }

        /** @var Model $prototype */
        $prototype = new $class;

        return $class::query()
            ->visibleTo($viewer, $permission)
            ->whereKey($ids)
            ->pluck($prototype->getKeyName())
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
