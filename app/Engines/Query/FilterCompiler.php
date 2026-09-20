<?php

declare(strict_types=1);

namespace App\Engines\Query;

use App\Support\KpiCatalogue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * One condition, compiled — for every screen that lets a company describe a set of records.
 *
 * THERE IS ONE OF THESE BECAUSE docs/11 §1 SAYS THERE MUST BE. "Every report query passes through
 * the same `visibleTo()` filter as the corresponding index... if a report and a list page disagree,
 * that is a bug." A KPI counting overdue deliverables and a report listing them are the same
 * question asked for a number and for rows, and the moment they are compiled by two different
 * classes they begin to drift: one learns about a new operator, the other does not; one qualifies
 * its columns, the other collides on an ambiguous join. Then a company reads 47 on a tile, clicks
 * it, counts 45 rows, and stops believing either.
 *
 * THE FIELD IS CHECKED HERE EVERY TIME, not merely when the definition was saved. A definition is
 * long-lived configuration and outlives the register it names (§84), so a column that was legal when
 * somebody typed it may since have been renamed or withdrawn. Asking again means such a definition
 * falls silent rather than reaching the database with a name nobody vouches for.
 *
 * FAILURE IS `false`, NEVER A LOOSER QUERY. Every refusal in this class either narrows to nothing or
 * reports back that the condition cannot be honoured. Nothing here has a path that drops an
 * unrecognised filter and carries on, because a filter that silently disappears turns "open
 * incidents in Civil" into "all incidents", and the answer arrives looking exactly as authoritative
 * as the right one.
 *
 * WHAT IT DOES NOT DO IS VISIBILITY. Callers apply `visibleTo()` before handing a query over, and
 * every method here only ever removes rows. That division is deliberate: a compiler that could also
 * widen would be one refactor away from being asked to.
 */
final class FilterCompiler
{
    /**
     * Apply a whole filter set.
     *
     * @param  Builder<Model>  $query
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $filters
     * @return bool false where a filter names something the register no longer offers
     */
    public function apply(Builder $query, string $entityType, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (! KpiCatalogue::offersFilter($entityType, $filter['field'])) {
                return false;
            }

            if (! $this->applyOne($query, $filter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One condition, placed.
     *
     * The field has already been checked against the register's own whitelist, so what remains is
     * to place it. A dotted field is a relation walk — `category.code` — and is compiled to a
     * `whereHas` rather than a join, because a join would multiply rows: it would turn a count of
     * documents into a count of document-category pairs, and a report listing documents into one
     * listing each document once per category it touches.
     *
     * @param  Builder<Model>  $query
     * @param  array{field:string,operator:string,value:mixed}  $filter
     */
    private function applyOne(Builder $query, array $filter): bool
    {
        if (! str_contains($filter['field'], '.')) {
            $this->compare($query, $filter['field'], $filter['operator'], $filter['value']);

            return true;
        }

        [$relation, $column] = explode('.', $filter['field'], 2);
        $model = $query->getModel();

        // The whitelist said the path was allowed; this says the relation is still there. A
        // register that dropped a relation must fall silent rather than throw inside a dashboard.
        if (! method_exists($model, $relation) || ! $model->{$relation}() instanceof Relation) {
            return false;
        }

        if (str_contains($column, '.')) {
            return false;
        }

        $query->whereHas($relation, function (Builder $related) use ($column, $filter) {
            $this->compare($related, $column, $filter['operator'], $filter['value']);
        });

        return true;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function compare(Builder $query, string $column, string $operator, mixed $value): void
    {
        $qualified = $query->getModel()->qualifyColumn($column);

        match ($operator) {
            'is_null' => $query->whereNull($qualified),
            'not_null' => $query->whereNotNull($qualified),
            'in' => $query->whereIn($qualified, $this->list($value)),
            'not_in' => $query->whereNotIn($qualified, $this->list($value)),
            'between' => $this->between($query, $qualified, $value),
            default => $query->where($qualified, $operator, $this->scalar($value)),
        };
    }

    /** @param  Builder<Model>  $query */
    private function between(Builder $query, string $column, mixed $value): void
    {
        $bounds = $this->list($value);

        if (count($bounds) !== 2) {
            // Half a range is not a range. Matching nothing is the honest reading of a condition
            // that cannot be evaluated; matching everything would inflate the number instead.
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereBetween($column, [$bounds[0], $bounds[1]]);
    }

    /** @return array<int,scalar> */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, fn ($item) => is_scalar($item)));
        }

        if (! is_scalar($value)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    private function scalar(mixed $value): mixed
    {
        return is_scalar($value) || $value === null ? $value : null;
    }
}
