<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\CustomFieldRegistry;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\OptionList;
use App\Models\User;
use App\Support\CustomFieldCatalogue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules behind the custom-field builder (§73).
 *
 * A field definition is not configuration in the way a colour is. It decides what a form demands,
 * what a column means and what an export contains, and it is edited by somebody who cannot see the
 * ten thousand records it already governs. Four refusals, each protecting something that cannot be
 * put back:
 *
 *   1. A KEY IS FROZEN ONCE ANSWERED. Values are filed against the field's id, so rekeying does
 *      not orphan them — but the key is what exports, reports and integrations name, and changing
 *      it silently renames a column somebody else's spreadsheet reads.
 *
 *   2. REQUIRED NEEDS A DEFAULT, OR NOBODY MAY HAVE ANSWERED YET. This is the one that bites.
 *      Ticking "required" on a register holding a year of records makes every one of them fail
 *      validation the next time anybody edits them — for a question nobody was asked at the time.
 *      So it is allowed when the field is new, when every existing record already answers it, or
 *      when the field carries a default that answers it for them.
 *
 *   3. THE TYPE IS FROZEN ONCE ANSWERED. Every value is text; changing 'text' to 'number' does not
 *      convert anything, it just means the next read casts 'approx 40' to null. A company that
 *      needs a different type makes a new field, which keeps the old answers readable.
 *
 *   4. A CHOICE FIELD MUST NAME A LIST THAT EXISTS. A select pointing at nothing is a required
 *      question with no permitted answer — a form that cannot be submitted, from a screen that
 *      gave no warning.
 *
 * DELETING A FIELD DELETES ITS ANSWERS, and that is offered anyway — with the count in the
 * refusal-shaped confirmation the controller asks for — because the alternative is a company
 * permanently unable to tidy up a field they created by mistake. Deactivating is the reversible
 * door, and it is the one the screen puts first.
 */
final class CustomFieldService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly ActivityRecorder $activity,
        private readonly CustomFieldRegistry $registry,
    ) {}

    // ================================================================== writing

    /** @param array<string,mixed> $attributes */
    public function create(array $attributes, User $actor): CustomField
    {
        $entityType = (string) ($attributes['entity_type'] ?? '');

        $this->assertKnownRegister($entityType);

        $label = trim((string) ($attributes['label'] ?? ''));

        if ($label === '') {
            throw new \DomainException('A field needs a label — the words that appear above the box.');
        }

        $key = $this->normaliseKey((string) ($attributes['key'] ?? ''), $label);
        $type = $this->assertKnownType((string) ($attributes['type'] ?? ''));

        $this->assertKeyIsFree($entityType, $key, null);

        $optionListKey = $this->assertChoiceHasAList($type, $attributes['option_list_key'] ?? null);

        return DB::transaction(function () use ($attributes, $entityType, $key, $label, $type, $optionListKey, $actor) {
            $field = CustomField::create([
                // §74. Never from input.
                'organization_id' => $this->context->idOrFail(),
                'entity_type' => $entityType,
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'option_list_key' => $optionListKey,
                'help' => trim((string) ($attributes['help'] ?? '')) ?: null,
                'placeholder' => trim((string) ($attributes['placeholder'] ?? '')) ?: null,
                'default_value' => $this->defaultFor($type, $attributes['default_value'] ?? null),

                // A brand-new field has no records behind it, so `required` needs no argument here.
                'is_required' => (bool) ($attributes['is_required'] ?? false),
                'is_filterable' => (bool) ($attributes['is_filterable'] ?? false),
                'is_active' => true,
                'sort' => $this->nextSort($entityType),
                'applies_when_key' => trim((string) ($attributes['applies_when_key'] ?? '')) ?: null,
                'applies_when_value' => trim((string) ($attributes['applies_when_value'] ?? '')) ?: null,
            ]);

            $this->activity->record($field, 'created',
                $actor->name.' added the "'.$label.'" field to '
                .CustomFieldCatalogue::label($entityType).'.', [], $actor);

            $this->registry->flush();

            return $field;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function update(CustomField $field, array $attributes, User $actor): CustomField
    {
        $label = trim((string) ($attributes['label'] ?? ''));

        if ($label === '') {
            throw new \DomainException('A field needs a label — the words that appear above the box.');
        }

        $answered = $this->timesAnswered($field);

        $key = array_key_exists('key', $attributes) && trim((string) $attributes['key']) !== ''
            ? $this->normaliseKey((string) $attributes['key'], $label)
            : $field->key;

        if ($key !== $field->key) {
            $this->assertKeyMayChange($field, $answered);
            $this->assertKeyIsFree($field->entity_type, $key, $field);
        }

        $type = array_key_exists('type', $attributes) && trim((string) $attributes['type']) !== ''
            ? $this->assertKnownType((string) $attributes['type'])
            : $field->type;

        if ($type !== $field->type) {
            $this->assertTypeMayChange($field, $answered);
        }

        $optionListKey = $this->assertChoiceHasAList($type, $attributes['option_list_key'] ?? $field->option_list_key);

        $required = (bool) ($attributes['is_required'] ?? false);
        $default = $this->defaultFor($type, $attributes['default_value'] ?? null);

        if ($required && ! $field->is_required) {
            $this->assertMayBecomeRequired($field, $default);
        }

        return DB::transaction(function () use (
            $field, $attributes, $label, $key, $type, $optionListKey, $required, $default, $actor
        ) {
            $field->fill([
                'label' => $label,
                'key' => $key,
                'type' => $type,
                'option_list_key' => $optionListKey,
                'help' => trim((string) ($attributes['help'] ?? '')) ?: null,
                'placeholder' => trim((string) ($attributes['placeholder'] ?? '')) ?: null,
                'default_value' => $default,
                'is_required' => $required,
                'is_filterable' => (bool) ($attributes['is_filterable'] ?? false),
                'is_active' => array_key_exists('is_active', $attributes)
                    ? (bool) $attributes['is_active']
                    : (bool) $field->is_active,
                'applies_when_key' => trim((string) ($attributes['applies_when_key'] ?? '')) ?: null,
                'applies_when_value' => trim((string) ($attributes['applies_when_value'] ?? '')) ?: null,
            ])->save();

            $this->activity->record($field, 'updated',
                $actor->name.' changed the "'.$label.'" field.', [], $actor);

            $this->registry->flush();

            return $field;
        });
    }

    /**
     * Remove a field and everything anybody answered into it.
     *
     * NO REFUSAL HERE, DELIBERATELY. A field created by mistake on a Monday is a field that must be
     * removable on a Tuesday, and a company that cannot tidy up its own configuration stops
     * trusting the screen. The protection is that the screen offers deactivation first and states
     * the count before asking — see the controller — and that the audit entry records what was
     * destroyed and by whom (§59).
     */
    public function delete(CustomField $field, User $actor): void
    {
        $answered = $this->timesAnswered($field);
        $label = $field->label;
        $entityType = $field->entity_type;

        DB::transaction(function () use ($field, $label, $entityType, $answered, $actor) {
            $this->activity->record($field, 'deleted',
                $actor->name.' deleted the "'.$label.'" field from '
                .CustomFieldCatalogue::label($entityType).'.',
                ['answers_destroyed' => $answered], $actor);

            CustomFieldValue::query()->where('custom_field_id', $field->getKey())->delete();

            $field->delete();

            $this->registry->flush();
        });
    }

    /**
     * Put a register's fields in the order they should be asked.
     *
     * Ids are trusted for ORDER ONLY; each is matched against this register's own fields, so an id
     * from another register — or another company — moves nothing (§75, §84).
     *
     * @param  array<int,int|string>  $orderedIds
     */
    public function reorder(string $entityType, array $orderedIds, User $actor): void
    {
        $fields = CustomField::query()->forEntity($entityType)->get()
            ->keyBy(fn (CustomField $field) => (int) $field->getKey());

        DB::transaction(function () use ($fields, $orderedIds, $entityType, $actor) {
            $sort = 0;

            foreach ($orderedIds as $id) {
                $field = $fields->get((int) $id);

                if ($field === null) {
                    continue;
                }

                $field->forceFill(['sort' => $sort++])->save();
            }

            if ($fields->isNotEmpty()) {
                $this->activity->record($fields->first(), 'updated',
                    $actor->name.' reordered the fields on '
                    .CustomFieldCatalogue::label($entityType).'.', [], $actor);
            }

            $this->registry->flush();
        });
    }

    // ================================================================== reading

    /**
     * Every register that may carry fields, with how many it carries.
     *
     * One grouped aggregate for the whole page rather than a count per row (§89).
     *
     * @return array<int,array{key:string,name:string,group:string,fields:int,active:int}>
     */
    public function registers(): array
    {
        $counts = CustomField::query()
            ->selectRaw('entity_type, COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as live')
            ->groupBy('entity_type')
            ->get()
            ->keyBy('entity_type');

        $out = [];

        foreach (CustomFieldCatalogue::all() as $entityType => [$group, $label]) {
            $row = $counts->get($entityType);

            $out[] = [
                'key' => $entityType,
                'name' => $label,
                'group' => $group,
                'fields' => (int) ($row->total ?? 0),
                'active' => (int) ($row->live ?? 0),
            ];
        }

        return $out;
    }

    /**
     * The fields defined on one register, including deactivated ones.
     *
     * Deactivated fields are shown because they still hold answers — a screen that hid them would
     * make an administrator think their data was gone.
     *
     * @return Collection<int,CustomField>
     */
    public function fieldsFor(string $entityType)
    {
        $this->assertKnownRegister($entityType);

        return CustomField::query()->forEntity($entityType)->ordered()->get();
    }

    /**
     * How many records have answered each of a register's fields.
     *
     * One query for the whole page. The counts are what make every refusal on this screen a
     * sentence with a number in it rather than a flat "not allowed".
     *
     * @return array<int,int> field id => answers
     */
    public function answerCounts(string $entityType): array
    {
        $ids = CustomField::query()->forEntity($entityType)->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return CustomFieldValue::query()
            ->selectRaw('custom_field_id, COUNT(*) as answered')
            ->whereIn('custom_field_id', $ids)
            ->whereNotNull('value')
            ->groupBy('custom_field_id')
            ->pluck('answered', 'custom_field_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * The option lists a choice field may point at.
     *
     * @return array<string,string> key => name
     */
    public function optionLists(): array
    {
        return OptionList::query()->where('is_active', true)->orderBy('name')
            ->pluck('name', 'key')->all();
    }

    // ================================================================== guards

    private function assertKnownRegister(string $entityType): void
    {
        if (! CustomFieldCatalogue::supports($entityType)) {
            // Not a validation message — a register that is not in the catalogue is not a register
            // this build can render fields on, and offering one would produce a field nobody ever
            // sees. §84: the URL said so, which is not evidence.
            throw new \DomainException('That is not a register fields can be added to.');
        }
    }

    private function assertKnownType(string $type): string
    {
        if (! array_key_exists($type, CustomField::TYPES)) {
            throw new \DomainException('That is not a kind of field this platform can render.');
        }

        return $type;
    }

    private function assertKeyIsFree(string $entityType, string $key, ?CustomField $except): void
    {
        if ($key === '') {
            throw new \DomainException('A field needs a key — a short name without spaces, used in exports.');
        }

        $exists = CustomField::query()
            ->forEntity($entityType)
            ->where('key', $key)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($exists) {
            throw new \DomainException(
                CustomFieldCatalogue::label($entityType).' already has a field with the key "'.$key.'".'
            );
        }
    }

    private function assertKeyMayChange(CustomField $field, int $answered): void
    {
        if ($answered === 0) {
            return;
        }

        throw new \DomainException(
            '"'.$field->label.'" has been answered on '.$answered.' '.Str::plural('record', $answered)
            .'. The key is what exports, reports and anything reading this data by name call the '
            .'column; changing it now would rename that column under everything already using it. '
            .'The label is yours to change freely — that is what people read.'
        );
    }

    private function assertTypeMayChange(CustomField $field, int $answered): void
    {
        if ($answered === 0) {
            return;
        }

        throw new \DomainException(
            '"'.$field->label.'" has been answered on '.$answered.' '.Str::plural('record', $answered)
            .', and every answer is stored as text. Changing the kind of field would not convert '
            .'them — it would leave answers this platform can no longer read. Add a new field of '
            .'the kind you need and switch this one off; the old answers stay readable.'
        );
    }

    /**
     * Making a field required is a change to records nobody is looking at.
     *
     * The three ways it is safe: the field is new, every existing record already answers it, or a
     * default answers on their behalf.
     */
    private function assertMayBecomeRequired(CustomField $field, ?string $default): void
    {
        if ($default !== null && $default !== '') {
            return;
        }

        $subjects = $this->recordsOnRegister($field->entity_type);

        if ($subjects === 0) {
            return;
        }

        $answered = $this->timesAnswered($field);

        if ($answered >= $subjects) {
            return;
        }

        $missing = $subjects - $answered;

        throw new \DomainException(
            $missing.' '.Str::plural('record', $missing).' on '
            .CustomFieldCatalogue::label($field->entity_type).' '
            .($missing === 1 ? 'has' : 'have').' no answer for "'.$field->label.'". Making it '
            .'required now would make '.($missing === 1 ? 'that record' : 'those records')
            .' fail validation the next time somebody edits '.($missing === 1 ? 'it' : 'them')
            .' — over a question nobody was asked at the time. Give the field a default answer '
            .'first, or fill in the gaps, and then make it required.'
        );
    }

    private function assertChoiceHasAList(string $type, mixed $given): ?string
    {
        if (! in_array($type, CustomField::CHOICE_TYPES, true)) {
            return null;
        }

        $key = is_string($given) ? trim($given) : '';

        if ($key === '') {
            throw new \DomainException(
                'A field people choose from needs a list to choose from. Pick one, or create it '
                .'on the Option lists screen first.'
            );
        }

        if (! OptionList::query()->forKey($key)->where('is_active', true)->exists()) {
            throw new \DomainException(
                'There is no option list called "'.$key.'". A field pointing at a list that does '
                .'not exist is a question with no permitted answer.'
            );
        }

        return $key;
    }

    // ================================================================== counting

    /** How many records have given this field a real answer. */
    private function timesAnswered(CustomField $field): int
    {
        return CustomFieldValue::query()
            ->where('custom_field_id', $field->getKey())
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->count();
    }

    /**
     * How many records exist on a register.
     *
     * Counted through the model so the tenant scope applies (§75), and tolerant of a register
     * whose table has not been migrated in this deployment — a guard that throws instead of
     * refusing is a guard that takes the screen down.
     */
    private function recordsOnRegister(string $entityType): int
    {
        $class = CustomFieldCatalogue::modelClass($entityType);

        if ($class === null || ! class_exists($class)) {
            return 0;
        }

        try {
            return (int) $class::query()->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ================================================================== helpers

    private function normaliseKey(string $given, string $fallback): string
    {
        $source = trim($given) !== '' ? $given : $fallback;

        return Str::of($source)->snake()->replace('-', '_')
            ->replaceMatches('/[^a-z0-9_]/', '')->limit(64, '')->trim('_')->toString();
    }

    private function defaultFor(string $type, mixed $given): ?string
    {
        if ($given === null) {
            return null;
        }

        $value = is_array($given) ? implode(',', $given) : trim((string) $given);

        if ($value === '') {
            return null;
        }

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
            'number' => is_numeric($value) ? (string) ($value + 0) : null,
            default => $value,
        };
    }

    private function nextSort(string $entityType): int
    {
        return (int) CustomField::query()->forEntity($entityType)->max('sort') + 1;
    }
}
