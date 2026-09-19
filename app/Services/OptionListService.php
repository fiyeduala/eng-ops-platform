<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Models\OptionList;
use App\Models\OptionListItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The rules behind the option-list editor (§63, §73).
 *
 * Every guard here defends the same thing: A VALUE A RECORD HAS ALREADY STORED MUST KEEP MEANING
 * WHAT IT MEANT. Custom field values store the item's `value` string, not its id, because a stored
 * id is meaningless in an export and dies with the row. The consequence is that `value` is data,
 * not presentation, and the moment anything references it the string is frozen.
 *
 * That gives four rules:
 *
 *   1. `label` IS EDITABLE FOREVER. Renaming "Mechanical" to "Mechanical & Piping" must cost
 *      nothing, because otherwise a company simply will not do it and the labels rot.
 *   2. `value` IS FROZEN ONCE USED. Changing it does not update anything — it strands every record
 *      that stored the old string, and they do not error, they just quietly mean nothing.
 *   3. AN OPTION IN USE IS RETIRED, NOT DELETED. `is_active` removes it from new dropdowns while
 *      leaving existing records readable. This is why deletion can afford to be strict: there is
 *      always a correct alternative, one click away.
 *   4. A LIST A FIELD DEPENDS ON IS NOT DELETABLE. Fields reference a list by key; deleting the
 *      list would leave a select field with nothing to select, on a form somebody is trying to
 *      submit.
 *
 * A SYSTEM LIST additionally keeps its key and its existence, because code names that key as a
 * literal — the same contract as Status::isSystem().
 */
final class OptionListService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ActivityRecorder $activity,
    ) {}

    // ================================================================== lists

    /** @param array<string,mixed> $attributes */
    public function createList(array $attributes, User $actor): OptionList
    {
        $key = $this->normaliseKey((string) ($attributes['key'] ?? ''), (string) ($attributes['name'] ?? ''));

        $this->assertKeyIsFree($key, null);

        return DB::transaction(function () use ($attributes, $key, $actor) {
            $list = OptionList::create([
                // §74. Never from input, under any circumstances, including an import.
                'organization_id' => $this->context->idOrFail(),
                'key' => $key,
                'name' => trim((string) ($attributes['name'] ?? '')),
                'description' => trim((string) ($attributes['description'] ?? '')) ?: null,
                'is_system' => false,
                'is_active' => true,
            ]);

            $this->activity->record($list, 'created',
                $actor->name.' created the "'.$list->name.'" list.', [], $actor);

            return $list;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function updateList(OptionList $list, array $attributes, User $actor): OptionList
    {
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw new \DomainException('A list needs a name.');
        }

        $key = array_key_exists('key', $attributes) && trim((string) $attributes['key']) !== ''
            ? $this->normaliseKey((string) $attributes['key'], $name)
            : $list->key;

        if ($key !== $list->key) {
            $this->assertMayRekey($list);
            $this->assertKeyIsFree($key, $list);
        }

        $active = array_key_exists('is_active', $attributes)
            ? (bool) $attributes['is_active']
            : (bool) $list->is_active;

        if (! $active && $list->isSystem()) {
            throw new \DomainException(
                '"'.$list->name.'" is a list the platform itself uses. It can be renamed, and its '
                .'options are yours to change, but it cannot be switched off.'
            );
        }

        return DB::transaction(function () use ($list, $name, $key, $attributes, $active, $actor) {
            $list->fill([
                'name' => $name,
                'key' => $key,
                'description' => trim((string) ($attributes['description'] ?? '')) ?: null,
                'is_active' => $active,
            ])->save();

            $this->activity->record($list, 'updated',
                $actor->name.' updated the "'.$list->name.'" list.', [], $actor);

            return $list;
        });
    }

    public function deleteList(OptionList $list, User $actor): void
    {
        if ($list->isSystem()) {
            throw new \DomainException(
                '"'.$list->name.'" is a list the platform itself uses and cannot be deleted. '
                .'Switch off the options you have stopped using instead.'
            );
        }

        $fields = $this->fieldsUsing($list);

        if ($fields !== []) {
            throw new \DomainException(
                '"'.$list->name.'" is what '.$this->andList($fields).' offers. Deleting it would '
                .'leave '.(count($fields) === 1 ? 'that field' : 'those fields').' with nothing to '
                .'choose from. Point the field at another list first, or remove the field.'
            );
        }

        $name = $list->name;

        DB::transaction(function () use ($list, $name, $actor) {
            $this->activity->record($list, 'deleted',
                $actor->name.' deleted the "'.$name.'" list.', [], $actor);

            $list->items()->delete();
            $list->delete();
        });
    }

    // ================================================================== items

    /** @param array<string,mixed> $attributes */
    public function createItem(OptionList $list, array $attributes, User $actor): OptionListItem
    {
        $label = trim((string) ($attributes['label'] ?? ''));

        if ($label === '') {
            throw new \DomainException('An option needs something written on it.');
        }

        $value = $this->normaliseKey((string) ($attributes['value'] ?? ''), $label);

        $this->assertValueIsFree($list, $value, null);

        return DB::transaction(function () use ($list, $attributes, $label, $value, $actor) {
            $item = OptionListItem::create([
                'organization_id' => $list->organization_id,
                'option_list_id' => $list->getKey(),
                'value' => $value,
                'label' => $label,
                'color' => $this->tone($attributes['color'] ?? null),
                'sort' => $this->nextSort($list),
                'is_active' => true,
            ]);

            $this->activity->record($list, 'updated',
                $actor->name.' added "'.$label.'" to the '.$list->name.' list.', [], $actor);

            return $item;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function updateItem(OptionListItem $item, array $attributes, User $actor): OptionListItem
    {
        $label = trim((string) ($attributes['label'] ?? ''));

        if ($label === '') {
            throw new \DomainException('An option needs something written on it.');
        }

        $list = $item->list;

        $value = array_key_exists('value', $attributes) && trim((string) $attributes['value']) !== ''
            ? $this->normaliseKey((string) $attributes['value'], $label)
            : $item->value;

        if ($value !== $item->value) {
            $this->assertValueMayChange($item);
            $this->assertValueIsFree($list, $value, $item);
        }

        $active = array_key_exists('is_active', $attributes)
            ? (bool) $attributes['is_active']
            : (bool) $item->is_active;

        // Absent is not the same as blank. An "is this still offered?" toggle posts no colour, and
        // a colour the company chose must not be quietly dropped by a form that never asked about
        // it. An explicitly empty colour still clears it, via tone().
        $color = array_key_exists('color', $attributes)
            ? $this->tone($attributes['color'])
            : $item->color;

        return DB::transaction(function () use ($item, $list, $label, $value, $color, $active, $actor) {
            $item->fill([
                'label' => $label,
                'value' => $value,
                'color' => $color,
                'is_active' => $active,
            ])->save();

            $this->activity->record($list, 'updated',
                $actor->name.' updated "'.$label.'" in the '.$list->name.' list.', [], $actor);

            return $item;
        });
    }

    public function deleteItem(OptionListItem $item, User $actor): void
    {
        $used = $this->timesChosen($item);

        if ($used > 0) {
            throw new \DomainException(
                '"'.$item->label.'" is recorded on '.$used.' '.Str::plural('record', $used)
                .'. Deleting it would leave '.($used === 1 ? 'that record' : 'those records')
                .' holding a value that no longer means anything. Switch it off instead — it will '
                .'stop being offered on new records and stay readable on the old ones.'
            );
        }

        $list = $item->list;
        $label = $item->label;

        DB::transaction(function () use ($item, $list, $label, $actor) {
            $this->activity->record($list, 'updated',
                $actor->name.' removed "'.$label.'" from the '.$list->name.' list.', [], $actor);

            $item->delete();
        });
    }

    /**
     * Put the options in the order they should be offered in.
     *
     * The ids are trusted for ORDER ONLY. Each is matched against a query scoped to this list, so
     * an id from another list — or another company — moves nothing (§75, §84).
     *
     * @param  array<int,int|string>  $orderedIds
     */
    public function reorderItems(OptionList $list, array $orderedIds, User $actor): void
    {
        $items = $list->items()->get()->keyBy(fn (OptionListItem $item) => (int) $item->getKey());

        DB::transaction(function () use ($items, $orderedIds, $list, $actor) {
            $sort = 0;

            foreach ($orderedIds as $id) {
                $item = $items->get((int) $id);

                if ($item === null) {
                    continue;
                }

                $item->forceFill(['sort' => $sort++])->save();
            }

            $this->activity->record($list, 'updated',
                $actor->name.' reordered the '.$list->name.' list.', [], $actor);
        });
    }

    // ================================================================== reading

    /**
     * Every list, with the counts the index needs — two queries, not one per row (§89).
     *
     * @return Collection<int,OptionList>
     */
    public function lists()
    {
        return OptionList::query()
            ->withCount([
                'items',
                'items as active_items_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * The options a form should offer for a list, by key.
     *
     * Returns value => label, active only and in the company's own order. Returns an empty array
     * for a list that does not exist rather than throwing, because a custom field may name a list
     * that has since been deleted and a form that 500s is worse than a select with nothing in it —
     * CustomFieldService reports that condition on the configuration screen, where it can be fixed.
     *
     * @return array<string,string>
     */
    public function optionsFor(string $listKey): array
    {
        $list = OptionList::query()->forKey($listKey)->where('is_active', true)->first();

        if ($list === null) {
            return [];
        }

        return $list->activeItems()->get()->pluck('label', 'value')->all();
    }

    // ================================================================== guards

    private function assertMayRekey(OptionList $list): void
    {
        if ($list->isSystem()) {
            throw new \DomainException(
                '"'.$list->name.'" is a list the platform itself refers to by name. Its name is '
                .'yours to change; its key is not.'
            );
        }

        $fields = $this->fieldsUsing($list);

        if ($fields !== []) {
            throw new \DomainException(
                $this->andList($fields).' '.(count($fields) === 1 ? 'refers' : 'refer')
                .' to this list by its key. Renaming the key would leave '
                .(count($fields) === 1 ? 'that field' : 'those fields').' pointing at nothing. '
                .'The list\'s name can be changed freely.'
            );
        }
    }

    private function assertKeyIsFree(string $key, ?OptionList $except): void
    {
        if ($key === '') {
            throw new \DomainException('A list needs a key — a short name without spaces.');
        }

        $exists = OptionList::query()
            ->forKey($key)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw new \DomainException('There is already a list called "'.$key.'".');
        }
    }

    /**
     * A value a record has stored is frozen.
     *
     * NOTHING IS UPDATED BY CHANGING IT, and that is the point. Records store the string; altering
     * the definition does not alter the copies, so the records do not break loudly — they quietly
     * hold a word that is no longer in the list, and nobody notices until a report comes out wrong.
     */
    private function assertValueMayChange(OptionListItem $item): void
    {
        $used = $this->timesChosen($item);

        if ($used === 0) {
            return;
        }

        throw new \DomainException(
            '"'.$item->label.'" is already recorded on '.$used.' '.Str::plural('record', $used)
            .', which stored its value rather than its wording. Changing the value now would leave '
            .($used === 1 ? 'that record' : 'those records').' holding a word this list no longer '
            .'contains. The wording can be changed freely — that is what people read.'
        );
    }

    private function assertValueIsFree(OptionList $list, string $value, ?OptionListItem $except): void
    {
        if ($value === '') {
            throw new \DomainException('An option needs a value — a short name without spaces.');
        }

        $exists = OptionListItem::query()
            ->where('option_list_id', $list->getKey())
            ->where('value', $value)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw new \DomainException('This list already has an option with the value "'.$value.'".');
        }
    }

    // ================================================================== dependency checks

    /**
     * Custom fields that offer this list.
     *
     * Asked through Schema::hasTable so that this service is correct in a deployment where the
     * custom-field tables have not been migrated yet — the two parts ship together, but a guard
     * that assumes the other half exists is a guard that throws instead of refusing.
     *
     * @return array<int,string>
     */
    private function fieldsUsing(OptionList $list): array
    {
        if (! Schema::hasTable('custom_fields')) {
            return [];
        }

        return DB::table('custom_fields')
            ->where('organization_id', $list->organization_id)
            ->where('option_list_key', $list->key)
            ->orderBy('label')
            ->pluck('label')
            ->map(fn ($label) => '"'.$label.'"')
            ->all();
    }

    /** How many records have actually stored this option. */
    private function timesChosen(OptionListItem $item): int
    {
        if (! Schema::hasTable('custom_fields') || ! Schema::hasTable('custom_field_values')) {
            return 0;
        }

        $fieldIds = DB::table('custom_fields')
            ->where('organization_id', $item->organization_id)
            ->where('option_list_key', $item->list->key)
            ->pluck('id');

        if ($fieldIds->isEmpty()) {
            return 0;
        }

        // A multiselect stores a JSON array of values, a select stores the bare string. Both are
        // checked, because "is this option in use" must not depend on which kind of field happens
        // to offer it.
        return DB::table('custom_field_values')
            ->whereIn('custom_field_id', $fieldIds)
            ->where(function ($query) use ($item) {
                $query->where('value', $item->value)
                    ->orWhere('value', 'like', '%"'.$item->value.'"%');
            })
            ->count();
    }

    // ================================================================== helpers

    private function normaliseKey(string $given, string $fallback): string
    {
        $source = trim($given) !== '' ? $given : $fallback;

        return Str::of($source)->snake()->replace('-', '_')
            ->replaceMatches('/[^a-z0-9_]/', '')->limit(64, '')->trim('_')->toString();
    }

    /**
     * A design-system tone, or nothing.
     *
     * Whitelisted rather than validated loosely: this string reaches a class attribute, and a
     * configuration field that reaches a class attribute is a configuration field that can style
     * the page (§84).
     */
    private function tone(mixed $given): ?string
    {
        $tone = is_string($given) ? trim($given) : '';

        return in_array($tone, ['neutral', 'brand', 'success', 'warning', 'danger'], true) ? $tone : null;
    }

    private function nextSort(OptionList $list): int
    {
        return (int) $list->items()->max('sort') + 1;
    }

    /** @param array<int,string> $things */
    private function andList(array $things): string
    {
        if (count($things) === 1) {
            return $things[0];
        }

        $last = array_pop($things);

        return implode(', ', $things).' and '.$last;
    }
}
