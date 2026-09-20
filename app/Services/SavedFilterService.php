<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\ActorProfile;
use App\Engines\Audit\ActivityRecorder;
use App\Models\KpiDefinition;
use App\Models\ReportDefinition;
use App\Models\SavedFilter;
use App\Models\User;
use App\Support\ReportCatalogue;

/**
 * Saving, sharing and removing a named set of conditions (§54).
 *
 * THE OWNER AND THE TENANT COME FROM THE ACTOR, NEVER FROM THE REQUEST (§74). There is no path
 * through this class by which a person can save a filter as somebody else, or into another
 * company, and the two columns that would allow it are set here rather than filled.
 *
 * SHARING IS THE PART THAT NEEDS AN ARGUMENT, and it is short: the name of a filter is company-
 * entered text that lands on other people's screens. "Contractors under investigation" tells
 * everybody who reads the register that there is an investigation, which is a disclosure made by
 * naming rather than by listing. So visibility is checked against what the sharer can actually
 * reach — a filter cannot be shared into a department the sharer is not in, and sharing to the
 * whole company is gated on the permission that already means "define a question for this company".
 *
 * THE CONDITIONS ARE WHITELISTED THE SAME WAY A REPORT'S ARE, through ReportCatalogue and against
 * the FILTER list rather than the printable-column list. Anything else would make a saved filter a
 * way around the asymmetry the report builder is careful about — free text is printable and is not
 * filterable, and a second door into filtering would be the whole point of the first door undone.
 */
class SavedFilterService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly ActorProfile $actors,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes, User $actor): SavedFilter
    {
        $filter = new SavedFilter;

        // Set here, from the actor, and never from `$attributes` (§74). The organization trait
        // would populate the tenant on its own; it is written explicitly all the same, because a
        // reader of this method should not have to know that to be sure of it.
        $filter->organization_id = $actor->organization_id;
        $filter->user_id = $actor->getKey();

        $this->fill($filter, $attributes, $actor);

        $filter->save();

        $this->activity->record(
            $filter,
            'created',
            $actor->name.' saved the view “'.$filter->name.'”.',
            ['entity_type' => $filter->entity_type, 'visibility' => $filter->visibility],
            $actor,
        );

        return $filter;
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function update(SavedFilter $filter, array $attributes, User $actor): SavedFilter
    {
        $was = $filter->visibility;

        $this->fill($filter, $attributes, $actor);

        $filter->save();

        $this->activity->record(
            $filter,
            'updated',
            $was === $filter->visibility
                ? $actor->name.' changed the view “'.$filter->name.'”.'
                : $actor->name.' changed who can see “'.$filter->name.'” to “'.$filter->visibilityLabel().'”.',
            ['entity_type' => $filter->entity_type, 'visibility' => $filter->visibility, 'was' => $was],
            $actor,
        );

        return $filter;
    }

    public function delete(SavedFilter $filter, User $actor): void
    {
        $this->activity->record(
            $filter,
            'deleted',
            $actor->name.' removed the view “'.$filter->name.'”.',
            ['entity_type' => $filter->entity_type, 'visibility' => $filter->visibility],
            $actor,
        );

        $filter->delete();
    }

    // ================================================================== validation

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function fill(SavedFilter $filter, array $attributes, User $actor): void
    {
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw new \DomainException('A saved view needs a name, or there is no way to pick it again.');
        }

        $entityType = (string) ($attributes['entity_type'] ?? $filter->entity_type ?? '');

        if (! ReportCatalogue::listable($entityType)) {
            throw new \DomainException('That is not a register this platform can filter.');
        }

        if ($this->nameTaken($filter, $name, $entityType, $actor)) {
            throw new \DomainException('You already have a view called “'.$name.'” for this register.');
        }

        $filter->name = mb_substr($name, 0, 120);
        $filter->entity_type = $entityType;
        $filter->filters = $this->conditions($entityType, $attributes['filters'] ?? []);

        [$filter->visibility, $filter->org_unit_id] = $this->sharing($attributes, $actor);
    }

    /**
     * The conditions, checked against the register's FILTER list.
     *
     * Refused rather than silently trimmed, for the reason the report service gives: a saved view
     * that quietly dropped one of its conditions would return a longer list than the person asked
     * for, look entirely normal, and be read as an answer.
     *
     * @param  mixed  $raw
     * @return array<int,array{field:string,operator:string,value:mixed}>
     */
    private function conditions(string $entityType, $raw): array
    {
        $conditions = [];

        foreach ((array) $raw as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');

            if ($field === '' && $operator === '') {
                continue;   // a blank row on the filter bar
            }

            if (! ReportCatalogue::offersFilter($entityType, $field)) {
                throw new \DomainException('That register cannot be filtered on “'.$field.'”.');
            }

            if (! array_key_exists($operator, KpiDefinition::FILTER_OPERATORS)) {
                throw new \DomainException('“'.$operator.'” is not a comparison this platform makes.');
            }

            $needsValue = ! in_array($operator, KpiDefinition::OPERATORS_WITHOUT_VALUE, true);
            $value = $condition['value'] ?? null;

            if ($needsValue && blank($value)) {
                throw new \DomainException('The condition on “'.$field.'” needs something to compare against.');
            }

            $conditions[] = [
                'field' => $field,
                'operator' => $operator,
                'value' => $needsValue ? (is_scalar($value) || is_array($value) ? $value : null) : null,
            ];
        }

        if ($conditions === []) {
            throw new \DomainException('A saved view needs at least one condition.');
        }

        if (count($conditions) > SavedFilter::MAX_FILTERS) {
            throw new \DomainException(
                'A saved view may carry at most '.SavedFilter::MAX_FILTERS.' conditions.'
            );
        }

        return $conditions;
    }

    /**
     * Who may see it, and — for a department share — which department.
     *
     * THE DEPARTMENT IS THE SHARER'S OWN, READ FROM THEIR POSITION IN THE TREE. It is never taken
     * from the request, so there is no `org_unit_id` anybody can post to put their filter on
     * another department's screens (§74, §84). A person with no position in the tree cannot make a
     * department share at all, because there is no department it would mean.
     *
     * @param  array<string,mixed>  $attributes
     * @return array{0:string,1:?int}
     */
    private function sharing(array $attributes, User $actor): array
    {
        $visibility = (string) ($attributes['visibility'] ?? 'private');

        if (! array_key_exists($visibility, SavedFilter::VISIBILITIES)) {
            throw new \DomainException('That is not a choice of who may see this view.');
        }

        if ($visibility === 'private') {
            return ['private', null];
        }

        if ($visibility === 'org_unit') {
            $orgUnitId = $this->actors->orgUnitId($actor);

            if ($orgUnitId === null) {
                throw new \DomainException(
                    'You are not recorded against a department, so there is no department to share this with.'
                );
            }

            return ['org_unit', $orgUnitId];
        }

        // Company-wide. The name of the view goes on everybody's screen, so this is the same
        // authority as defining a report for the company to use — and it is a permission some
        // roles already hold, rather than a new one nobody has been granted.
        if (! $actor->can('create', ReportDefinition::class)) {
            throw new \DomainException(
                'Sharing a view with the whole company is part of building reports for it, which '
                .'is not something your role does. Your department can still see it.'
            );
        }

        return ['organization', null];
    }

    private function nameTaken(SavedFilter $filter, string $name, string $entityType, User $actor): bool
    {
        return SavedFilter::query()
            ->where('user_id', $actor->getKey())
            ->where('entity_type', $entityType)
            ->where('name', $name)
            ->when($filter->exists, fn ($query) => $query->whereKeyNot($filter->getKey()))
            ->exists();
    }
}
