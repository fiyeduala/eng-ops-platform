<?php

declare(strict_types=1);

namespace App\Engines\Configuration;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Services\OptionListService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Engine 5's other half: the fields a company added, and the rules that keep them honest (§73).
 *
 * THIS CLASS IS A VALIDATION BOUNDARY BEFORE IT IS ANYTHING ELSE. Every value lands in one TEXT
 * column, so the declared type is the only thing between that column and whatever arrived in the
 * request. A "number" field whose rules were skipped is a text column holding 'abc' that a report
 * will one day SUM; a "select" field whose rules were skipped is a controlled vocabulary with an
 * arbitrary string in it (§84). The rules are therefore compiled here, from the definitions, and
 * applied server-side on every write — never from a data-* attribute the form happened to carry.
 *
 * THREE THINGS IT DELIBERATELY DOES NOT DO:
 *
 *   - It does not decide who may read a value. Values have no visibility of their own; they are
 *     reachable only through a record the access engine has already granted (§6).
 *   - It does not write a value for a field the request did not mention. A status change posts no
 *     custom fields, and a save that treated "absent" as "empty" would erase a year of answers on
 *     the way past.
 *   - It does not back-fill defaults onto existing records. A default is what a NEW record starts
 *     with; applying one retrospectively invents data on records that predate the field.
 */
class CustomFieldRegistry
{
    /** The request key every form posts custom values under. */
    public const INPUT_KEY = 'custom_fields';

    /** @var array<string,Collection<int,CustomField>> */
    private array $memo = [];

    public function __construct(
        private readonly OptionListService $options,
    ) {}

    // ================================================================== definitions

    /**
     * The active fields for a register, in the order an administrator arranged them.
     *
     * Memoized per instance: a listing that renders forty records asks forty times and the answer
     * is the same forty times (§89).
     *
     * @return Collection<int,CustomField>
     */
    public function fieldsFor(string $entityType): Collection
    {
        return $this->memo[$entityType] ??= CustomField::query()
            ->forEntity($entityType)
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * The fields that apply to one particular record.
     *
     * A field may be narrowed to a kind of record — see CustomField::appliesTo(). On a blank
     * create form there is nothing to narrow against, so everything is offered.
     *
     * @return Collection<int,CustomField>
     */
    public function applicableTo(string $entityType, ?Model $record = null): Collection
    {
        return $this->fieldsFor($entityType)
            ->filter(fn (CustomField $field) => $field->appliesTo($record))
            ->values();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    // ================================================================== validation

    /**
     * Laravel rules for a register's custom fields, keyed as the form posts them.
     *
     * @return array<string,mixed>
     */
    public function rules(string $entityType, ?Model $record = null): array
    {
        $rules = [self::INPUT_KEY => ['sometimes', 'array']];

        foreach ($this->applicableTo($entityType, $record) as $field) {
            $key = self::INPUT_KEY.'.'.$field->key;

            $rules[$key] = $this->rulesForField($field);

            if ($field->isMultiple()) {
                $rules[$key.'.*'] = ['string', Rule::in($this->choicesFor($field))];
            }
        }

        return $rules;
    }

    /**
     * Human names so a failure reads "Client cost code is required", not "custom_fields.cc".
     *
     * @return array<string,string>
     */
    public function attributeNames(string $entityType): array
    {
        $names = [];

        foreach ($this->fieldsFor($entityType) as $field) {
            $names[self::INPUT_KEY.'.'.$field->key] = strtolower($field->label);
        }

        return $names;
    }

    /** @return array<int,mixed> */
    private function rulesForField(CustomField $field): array
    {
        // REQUIRED IS ONLY EVER APPLIED TO A FIELD THAT APPLIES. The caller has already narrowed
        // the set; anything reaching here is genuinely being asked of this record.
        $rules = [$field->is_required ? 'required' : 'nullable'];

        return match ($field->type) {
            'text' => [...$rules, 'string', 'max:255'],
            'textarea' => [...$rules, 'string', 'max:5000'],
            'number' => [...$rules, 'numeric'],
            'date' => [...$rules, 'date'],
            'boolean' => [...$rules, 'boolean'],

            // The `in` rule is the whole point of a controlled vocabulary. Without it a select is
            // a text box with a nicer control on top of it (§84).
            'select' => [...$rules, 'string', Rule::in($this->choicesFor($field))],
            'multiselect' => [...$rules, 'array'],

            // A type this build does not know — a field defined by a newer version, or edited in
            // the database. Accept it as bounded text rather than letting it through unchecked.
            default => [...$rules, 'string', 'max:255'],
        };
    }

    /**
     * The values a choice field will accept.
     *
     * An empty array is a real answer: it means the list was deleted or emptied, and `in:[]`
     * refuses everything, which is correct — a select with nothing in it must not accept a value
     * somebody posted from a stale page.
     *
     * @return array<int,string>
     */
    private function choicesFor(CustomField $field): array
    {
        if (($field->option_list_key ?? '') === '') {
            return [];
        }

        return array_keys($this->options->optionsFor($field->option_list_key));
    }

    // ================================================================== reading

    /**
     * The stored answers for one record, keyed by field, cast to their declared types.
     *
     * Reads the eager-loaded relation when the caller loaded it and queries once when they did
     * not. A listing should load it; see HasCustomFields::scopeWithCustomFields (§89).
     *
     * @return array<string,mixed>
     */
    public function valuesFor(Model $record): array
    {
        $fields = $this->fieldsFor($this->entityTypeOf($record))->keyBy('id');

        $values = $record->relationLoaded('customFieldValues')
            ? $record->getRelation('customFieldValues')
            : CustomFieldValue::query()
                ->where('subject_type', $record->getMorphClass())
                ->where('subject_id', $record->getKey())
                ->get();

        $out = [];

        foreach ($values as $value) {
            $field = $fields->get($value->custom_field_id);

            if ($field === null) {
                // A value belonging to a field that has since been deactivated or deleted. It stays
                // in the table — §59 keeps history — but it is not shown as if it were current.
                continue;
            }

            $out[$field->key] = $value->typed($field->type);
        }

        return $out;
    }

    /**
     * The answers arranged for display: the field, the raw value and what to print.
     *
     * Choice values are resolved through the option list so a record shows "Mechanical & Piping"
     * even though it stored 'mechanical' — which is exactly why the two are kept apart.
     *
     * @return array<int,array{field:CustomField,value:mixed,display:string}>
     */
    public function displayFor(Model $record): array
    {
        $values = $this->valuesFor($record);
        $out = [];

        foreach ($this->applicableTo($this->entityTypeOf($record), $record) as $field) {
            if (! array_key_exists($field->key, $values)) {
                continue;
            }

            $out[] = [
                'field' => $field,
                'value' => $values[$field->key],
                'display' => $this->render($field, $values[$field->key]),
            ];
        }

        return $out;
    }

    private function render(CustomField $field, mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '—';
        }

        if ($field->isChoice()) {
            $labels = $this->options->optionsFor((string) $field->option_list_key);

            $chosen = is_array($value) ? $value : [$value];

            return implode(', ', array_map(
                // A value whose option was deleted still prints — as the raw value, because
                // silently dropping it would make a record look blank when it is not.
                fn ($one) => $labels[$one] ?? (string) $one,
                $chosen,
            ));
        }

        return match ($field->type) {
            'boolean' => $value ? 'Yes' : 'No',
            default => is_array($value) ? implode(', ', $value) : (string) $value,
        };
    }

    // ================================================================== writing

    /**
     * Store the answers a request carried, and only those.
     *
     * A field the input does not mention is left exactly as it was — see the class docblock. An
     * answer that arrives empty DELETES its row rather than storing '', so "never answered" and
     * "answered blank" do not become two different kinds of nothing in every later report.
     *
     * NO ACTOR PARAMETER, on purpose. Every write here passes through CustomFieldValue, which is
     * Auditable — the audit engine already knows who is acting (§59). A second, hand-passed actor
     * would be a second answer to that question, and the two would eventually disagree.
     *
     * Returns the keys whose answer actually changed, so a caller can tell a real edit from a form
     * that posted back what was already there — RegisterService uses it to decide whether the
     * record's timeline has anything to say.
     *
     * @param  array<string,mixed>  $input  the `custom_fields` array from the request
     * @return array<int,string>
     */
    public function save(Model $record, array $input): array
    {
        $fields = $this->applicableTo($this->entityTypeOf($record), $record);

        if ($fields->isEmpty() || $input === []) {
            return [];
        }

        return DB::transaction(function () use ($fields, $input, $record) {
            $existing = CustomFieldValue::query()
                ->where('subject_type', $record->getMorphClass())
                ->where('subject_id', $record->getKey())
                ->get()
                ->keyBy('custom_field_id');

            $changed = [];

            foreach ($fields as $field) {
                if (! array_key_exists($field->key, $input)) {
                    continue;
                }

                $stored = $this->normalise($field, $input[$field->key]);
                $before = $existing->get($field->getKey())?->value;

                if ($stored === $before) {
                    continue;
                }

                $changed[] = $field->key;

                $match = [
                    'custom_field_id' => $field->getKey(),
                    'subject_type' => $record->getMorphClass(),
                    'subject_id' => $record->getKey(),
                ];

                if ($stored === null) {
                    CustomFieldValue::query()->where($match)->delete();

                    continue;
                }

                CustomFieldValue::query()->updateOrCreate($match, [
                    // §74. The record's, never the request's — and the record's rather than the
                    // actor's, so a value can never end up filed under a different company from
                    // the thing it describes.
                    'organization_id' => $record->getAttribute('organization_id'),
                    'value' => $stored,
                ]);
            }

            if ($changed !== []) {
                // The relation this record is carrying is now a lie, and under preventLazyLoading
                // a caller that re-reads it would get the stale copy rather than an error.
                $record->unsetRelation('customFieldValues');
            }

            return $changed;
        });
    }

    /**
     * Apply a new record's defaults.
     *
     * Called on create only, and only for fields the request did not answer. This is the one place
     * a default is ever written, which is what keeps "the default changed" from meaning "every old
     * record changed".
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function withDefaults(string $entityType, array $input): array
    {
        foreach ($this->applicableTo($entityType) as $field) {
            $given = $input[$field->key] ?? null;

            if ($given !== null && $given !== '' && $given !== []) {
                continue;
            }

            if (($field->default_value ?? '') === '') {
                continue;
            }

            $input[$field->key] = $field->isMultiple()
                ? array_filter(array_map('trim', explode(',', (string) $field->default_value)))
                : $field->default_value;
        }

        return $input;
    }

    /** One answer, as the text column will hold it — or null, meaning "no answer". */
    private function normalise(CustomField $field, mixed $value): ?string
    {
        if ($field->isMultiple()) {
            $chosen = array_values(array_filter(
                array_map(fn ($one) => is_scalar($one) ? trim((string) $one) : '', (array) $value),
                fn (string $one) => $one !== '',
            ));

            return $chosen === [] ? null : (string) json_encode($chosen);
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            // A boolean is the exception: an unchecked box posts '0', and a box nobody has ever
            // seen posts nothing at all. Only the second is "no answer".
            return null;
        }

        return match ($field->type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? '1' : '0',
            'number' => is_numeric($value) ? (string) ($value + 0) : null,
            'date' => $this->asDate($value),
            default => trim((string) $value),
        };
    }

    private function asDate(mixed $value): ?string
    {
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            // Unreachable through a validated request; reachable from an importer. Refusing to
            // store an unparseable date is better than storing a string nothing can read.
            return null;
        }
    }

    /**
     * The catalogue key for a record.
     *
     * The morph alias IS the entity type, for every register in the catalogue. That is not a
     * coincidence to rely on quietly: the morph map, the status tables, the numbering schemes and
     * the permission catalogue were all given the same string on purpose, so a register is one
     * word everywhere. CustomFieldCatalogueTest holds the two in step.
     *
     * A model outside the catalogue simply has no fields defined against its alias, which the
     * empty collection expresses correctly without a special case.
     */
    private function entityTypeOf(Model $record): string
    {
        return $record->getMorphClass();
    }
}
