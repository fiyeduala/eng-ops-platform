<?php

declare(strict_types=1);

namespace App\Engines\Configuration;

use App\Models\FormAnswer;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\FormVersion;
use App\Models\Signature;
use App\Services\OptionListService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * A published form turned into rules, and a request turned into answers (Phase 7, Part 3D).
 *
 * THIS IS A VALIDATION BOUNDARY BEFORE IT IS ANYTHING ELSE, and it is the same boundary
 * CustomFieldRegistry draws for the fields a company adds to a register — see that class for the
 * argument in full. Every answer lands in one TEXT column, so the declared type is the only thing
 * between that column and whatever arrived. The rules are compiled HERE, from the stored
 * definition, on every path in: the fill-in screen, a future import, a workflow action. Nothing is
 * read from the page that was posted (§84).
 *
 * WHAT IS DIFFERENT FROM CUSTOM FIELDS, and why this is a second class rather than a parameter on
 * the first:
 *
 *   THE DEFINITION IS A VERSION, NOT A LIVE SET. Custom fields are compiled from whatever is
 *   active now. A submission is compiled from the edition it was started against — always, even
 *   when the form has moved on twice since. That is the whole point of the versioning in Part 3B,
 *   and it means every method here takes a FormVersion rather than an entity type.
 *
 *   REQUIRED IS NOT A VALIDATION RULE HERE AT ALL. A draft is half-answered by definition — that
 *   is what a draft IS — so the rules this class compiles are type rules, always, and never
 *   `required`. Completeness is judged AFTER the answers are written, by missingRequired(), and
 *   FormSubmissionService::submit() is what refuses on it. That ordering is deliberate and it is
 *   the reason the split exists: a `required` rule at the request boundary rejects the whole
 *   payload, so a person who presses Submit with one box empty loses the other forty-nine answers
 *   they just typed. Writing first and judging second costs one extra query and keeps their work.
 *   It also means completeness is asked in ONE place for both ordinary questions and table rows,
 *   rather than in two that can drift apart.
 *
 *   ANSWERS HAVE ROWS. A repeating table posts answers.<table>.<row>.<column>, and each cell is
 *   its own FormAnswer carrying a row_index. Twelve scaffold bays are twelve rows a report can
 *   count rather than a JSON blob it has to parse.
 *
 * THREE THINGS IT DELIBERATELY DOES NOT DO:
 *
 *   - It does not decide who may read an answer. Answers have no visibility of their own; they are
 *     reachable only through a submission the access engine has already ruled on (§6).
 *   - It does not decide whether a submission may be written to at all. That is a question about
 *     the record's state, and FormSubmissionService owns it — so that every path enforces it.
 *   - It does not let `config` widen anything. min/max/max_length TIGHTEN a rule the type already
 *     imposes and are clamped to the type's own ceiling before use; a crafted config makes a field
 *     stricter or makes no difference, and can never make it looser.
 */
class FormCompiler
{
    /** The request key every fill-in screen posts answers under. */
    public const INPUT_KEY = 'answers';

    /** How many rows a repeating table will accept when its author set no ceiling. */
    private const TABLE_ROW_LIMIT = 200;

    /** @var array<int,Collection<int,FormField>> */
    private array $memo = [];

    public function __construct(
        private readonly OptionListService $options,
    ) {}

    // ================================================================== the definition

    /**
     * Every field on an edition, columns included, in the author's order.
     *
     * Memoized per version for the length of the request: rendering a submission asks for the
     * definition once per section and once per answer, and the answer is the same every time (§89).
     *
     * @return Collection<int,FormField>
     */
    public function fieldsFor(FormVersion $version): Collection
    {
        return $this->memo[(int) $version->getKey()] ??= $version->relationLoaded('fields')
            ? $version->getRelation('fields')
            : $version->fields()->get();
    }

    /** The questions a person is asked: top level only, table columns excluded. */
    public function questionsFor(FormVersion $version): Collection
    {
        return $this->fieldsFor($version)->whereNull('parent_field_id')->values();
    }

    /** The columns of one repeating table, in order. */
    public function columnsOf(FormVersion $version, FormField $table): Collection
    {
        return $this->fieldsFor($version)
            ->where('parent_field_id', $table->getKey())
            ->values();
    }

    /**
     * The questions that apply, given what has been answered so far.
     *
     * A question narrowed to "only when has_permit is no" is not asked of a submission that said
     * yes — so it is not validated, not required and not counted as missing. See
     * FormField::appliesTo() for why an unanswered dependency shows the question rather than
     * hiding it.
     *
     * @param  array<string,mixed>  $given  the `answers` array, keyed by field key
     * @return Collection<int,FormField>
     */
    public function applicableTo(FormVersion $version, array $given): Collection
    {
        return $this->questionsFor($version)
            ->filter(fn (FormField $field) => $field->appliesTo($given))
            ->values();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    // ================================================================== validation

    /**
     * Laravel rules for one edition, keyed as the screen posts them.
     *
     * @param  array<string,mixed>  $given  the posted answers, used to narrow conditional questions
     * @return array<string,mixed>
     */
    public function rules(FormVersion $version, array $given): array
    {
        $rules = [self::INPUT_KEY => ['sometimes', 'array']];

        foreach ($this->applicableTo($version, $given) as $field) {
            $key = self::INPUT_KEY.'.'.$field->key;

            if ($field->isTable()) {
                $rules += $this->tableRules($version, $field, $key);

                continue;
            }

            if (! $this->collectsAnswer($field)) {
                continue;
            }

            $rules[$key] = ['nullable', ...$this->typeRules($field)];

            if ($field->isMultiple()) {
                $rules[$key.'.*'] = ['string', Rule::in($this->choicesFor($field))];
            }
        }

        return $rules;
    }

    /**
     * Human names, so a failure reads "Inspector name is required" rather than
     * "answers.inspector is required".
     *
     * @return array<string,string>
     */
    public function attributeNames(FormVersion $version): array
    {
        $names = [];

        // Parents resolved from the collection already in hand rather than through the relation:
        // lazy loading is disabled outside production, and this runs once per field.
        $byId = $this->fieldsFor($version)->keyBy('id');

        foreach ($this->fieldsFor($version) as $field) {
            $key = $field->isColumn()
                ? self::INPUT_KEY.'.'.($byId->get($field->parent_field_id)?->key ?? '*').'.*.'.$field->key
                : self::INPUT_KEY.'.'.$field->key;

            $names[$key] = strtolower($field->label);
        }

        return $names;
    }

    /**
     * Rules for a repeating table.
     *
     * A ROW THAT IS ENTIRELY BLANK IS NOT A ROW. The screen offers empty rows to type into, and a
     * person who fills in three of five has not left two rows incomplete — they have filled in
     * three. Whether a row somebody DID start is finished is a question about the table rather than
     * about one cell, so it is answered where every other completeness question is answered:
     * missingRequired(), after the write.
     *
     * @return array<string,mixed>
     */
    private function tableRules(FormVersion $version, FormField $table, string $key): array
    {
        $columns = $this->columnsOf($version, $table);

        $max = (int) $table->setting('max_rows', 0);
        $ceiling = $max > 0 ? min($max, self::TABLE_ROW_LIMIT) : self::TABLE_ROW_LIMIT;

        $rules = [
            $key => ['sometimes', 'array', 'max:'.$ceiling],
            $key.'.*' => ['array'],
        ];

        foreach ($columns as $column) {
            if (! $this->collectsAnswer($column)) {
                continue;
            }

            $cell = $key.'.*.'.$column->key;

            // NEVER `required`, at any moment — see the method docblock. A row's own completeness
            // is judged against rows that have content, which is a question about the whole table
            // rather than about one cell, and FormCompiler::missingRequired() answers it for the
            // screen and FormSubmissionService::submit() enforces it on the way in.
            $rules[$cell] = ['nullable', ...$this->typeRules($column)];

            if ($column->isMultiple()) {
                $rules[$cell.'.*'] = ['string', Rule::in($this->choicesFor($column))];
            }
        }

        return $rules;
    }

    /**
     * What the type itself insists on — the part that holds whether this is a draft or not.
     *
     * A draft with 'abc' in a number column is a draft that cannot be submitted and a column a
     * report will one day SUM. Type rules therefore apply at every moment; only `required` waits.
     *
     * @return array<int,mixed>
     */
    private function typeRules(FormField $field): array
    {
        return match ($field->type) {
            'text' => ['string', 'max:'.$this->clamp($field, 'max_length', 255)],
            'textarea' => ['string', 'max:'.$this->clamp($field, 'max_length', 5000)],
            'number' => $this->numberRules($field),
            'date' => $this->dateRules($field),
            'boolean' => ['boolean'],

            // The `in` rule is the whole point of a controlled vocabulary. Without it a select is
            // a text box with a nicer control on top of it (§84).
            'select' => ['string', Rule::in($this->choicesFor($field))],
            'multiselect' => ['array'],

            // A type this build does not know — one written by a newer version, or edited straight
            // into the database. Accepted as bounded text rather than let through unchecked.
            default => ['string', 'max:255'],
        };
    }

    /** @return array<int,mixed> */
    private function numberRules(FormField $field): array
    {
        $rules = ['numeric'];

        foreach (['min', 'max'] as $bound) {
            $value = $field->setting($bound);

            if ($value !== null && $value !== '' && is_numeric($value)) {
                $rules[] = $bound.':'.($value + 0);
            }
        }

        return $rules;
    }

    /** @return array<int,mixed> */
    private function dateRules(FormField $field): array
    {
        $rules = ['date'];

        foreach (['min' => 'after_or_equal', 'max' => 'before_or_equal'] as $bound => $rule) {
            $value = $field->setting($bound);

            if (is_string($value) && trim($value) !== '') {
                $rules[] = $rule.':'.trim($value);
            }
        }

        return $rules;
    }

    /**
     * A length from config, never above the type's own ceiling.
     *
     * The one place config touches a rule, and it is clamped on the way past. An author may say a
     * short-text answer is at most 40 characters; nobody may say it is at most 4,000, because the
     * column and every export built on it assume the type's ceiling (§84).
     */
    private function clamp(FormField $field, string $key, int $ceiling): int
    {
        $asked = (int) $field->setting($key, 0);

        return $asked > 0 ? min($asked, $ceiling) : $ceiling;
    }

    /**
     * The values a choice field will accept.
     *
     * An empty array is a real answer: it means the list was deleted or emptied, and `in:[]`
     * refuses everything — which is correct, because a select with nothing in it must not accept a
     * value posted from a page somebody left open last month.
     *
     * @return array<int,string>
     */
    private function choicesFor(FormField $field): array
    {
        if (($field->option_list_key ?? '') === '') {
            return [];
        }

        return array_keys($this->options->optionsFor((string) $field->option_list_key));
    }

    /**
     * Whether this field puts anything in the answers table.
     *
     * A heading and a note display. A table is a container; its columns collect. A SIGNATURE BLOCK
     * COLLECTS NOTHING HERE EITHER — what it gathers is a Signature, written once and carrying a
     * hash of what was signed, and routing that through a text column would be a second, weaker
     * answer to "what does an approval prove".
     */
    private function collectsAnswer(FormField $field): bool
    {
        return $field->answersSomething() && ! $field->isSignature();
    }

    // ================================================================== reading

    /**
     * Everything a submission says, keyed by field key.
     *
     * An ordinary question maps to its typed value. A repeating table maps to a list of rows, each
     * keyed by column key — so a view and an export read the same shape and neither has to know
     * about row_index.
     *
     * @return array<string,mixed>
     */
    public function valuesFor(FormSubmission $submission): array
    {
        $version = $submission->relationLoaded('version')
            ? $submission->getRelation('version')
            : $submission->version()->firstOrFail();

        $fields = $this->fieldsFor($version)->keyBy('id');

        $answers = $submission->relationLoaded('answers')
            ? $submission->getRelation('answers')
            : $submission->answers()->get();

        $out = [];
        $rows = [];

        foreach ($answers as $answer) {
            $field = $fields->get($answer->form_field_id);

            if ($field === null) {
                // An answer against a field that is no longer on this version — only reachable if
                // somebody edited the database, since a published version's fields never move. It
                // stays in the table (§59) and is not shown as though it were current.
                continue;
            }

            if ($field->isColumn()) {
                $table = $fields->get($field->parent_field_id);

                if ($table !== null) {
                    $rows[$table->key][(int) $answer->row_index][$field->key] = $answer->typed($field->type);
                }

                continue;
            }

            $out[$field->key] = $answer->typed($field->type);
        }

        foreach ($rows as $tableKey => $byIndex) {
            ksort($byIndex);
            $out[$tableKey] = array_values($byIndex);
        }

        return $out;
    }

    /**
     * One answer as a person reads it.
     *
     * Choice values resolve through the option list, so a submission shows "Mechanical & Piping"
     * though it stored 'mechanical' — which is exactly why the two are kept apart. A value whose
     * option has since been deleted still prints, as itself: dropping it would make a submission
     * look blank when it is not.
     */
    public function render(FormField $field, mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '—';
        }

        if ($field->isChoice()) {
            $labels = $this->options->optionsFor((string) $field->option_list_key);

            return implode(', ', array_map(
                fn ($one) => $labels[$one] ?? (string) $one,
                is_array($value) ? $value : [$value],
            ));
        }

        return match ($field->type) {
            'boolean' => $value ? 'Yes' : 'No',
            'date' => $this->renderDate($value),
            default => is_array($value) ? implode(', ', $value) : (string) $value,
        };
    }

    private function renderDate(mixed $value): string
    {
        try {
            return Carbon::parse((string) $value)->format('j M Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    /**
     * The choices a field offers, for a screen to render.
     *
     * @return array<string,string>
     */
    public function choicesForDisplay(FormField $field): array
    {
        return ($field->option_list_key ?? '') === ''
            ? []
            : $this->options->optionsFor((string) $field->option_list_key);
    }

    // ================================================================== signing

    /**
     * A fingerprint of everything this submission currently says.
     *
     * WHAT A SIGNATURE ON A FORM IS BOUND TO. The document module hashes the exact bytes of a
     * revision; a submission has no bytes, so the equivalent is its answers — every one of them,
     * in a canonical order, so that the same answers always hash the same way whatever order they
     * were typed or read back in. Change one reading after somebody signed and the two hashes stop
     * agreeing, which is the whole point: a signature that silently carried onto altered answers
     * would be worse than no signature at all (§28).
     *
     * SIGNATURES ARE NOT IN THE HASH, deliberately. Two people signing the same sheet must not
     * invalidate each other, and a counter-signature is not a change to what was signed.
     */
    public function contentHash(FormSubmission $submission): string
    {
        return $this->hashOf($this->valuesFor($submission));
    }

    /** @param  array<string,mixed>  $values */
    private function hashOf(array $values): string
    {
        return hash('sha256', (string) json_encode($this->canonicalise($values)));
    }

    /**
     * The signatures standing against one block right now.
     *
     * THE LATEST ROW PER PERSON, AND ONLY IF IT IS A SIGNING. A signature is written once and never
     * edited — withdrawing one is a new row saying so — so "who has signed this block" is a
     * question about the last thing each person did, not about how many rows they have. Reading it
     * any other way would make a withdrawn approval count forever.
     *
     * @return Collection<int,Signature>
     */
    public function signaturesOn(FormSubmission $submission, FormField $field): Collection
    {
        $all = $submission->relationLoaded('signatures')
            ? $submission->getRelation('signatures')
            : $submission->signatures()->get();

        return $all
            ->filter(fn (Signature $one) => (int) ($one->provider_reference['field_id'] ?? 0) === (int) $field->getKey())
            ->sortBy([['signed_at', 'asc'], ['id', 'asc']])
            ->groupBy(fn (Signature $one) => (string) $one->user_id)
            ->map(fn (Collection $theirs) => $theirs->last())
            ->filter(fn (Signature $one) => $one->action === 'sign')
            ->sortBy('signed_at')
            ->values();
    }

    /**
     * Answers in one settled order, so a hash over them means something.
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function canonicalise(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->canonicalise($value);
            }
        }

        return $values;
    }

    // ================================================================== writing

    /**
     * Store the answers a request carried, and only those.
     *
     * A QUESTION THE INPUT DOES NOT MENTION IS LEFT EXACTLY AS IT WAS. Custom fields make the same
     * promise and for the same reason: a partial save — a status change, a workflow action, a
     * screen that posts one section — must not read as "everything else was cleared". An answer
     * that arrives EMPTY deletes its row rather than storing '', so "never answered" and "answered
     * blank" do not become two different kinds of nothing in every later report.
     *
     * NO ACTOR PARAMETER, on purpose: every write passes through FormAnswer, which is Auditable,
     * and the audit engine already knows who is acting (§59).
     *
     * Returns the keys whose stored answer actually changed, so a caller can tell a real edit from
     * a screen that posted back what was already on it.
     *
     * @param  array<string,mixed>  $input  the `answers` array from the request
     * @return array<int,string>
     */
    public function save(FormSubmission $submission, array $input): array
    {
        if ($input === []) {
            return [];
        }

        $version = $submission->relationLoaded('version')
            ? $submission->getRelation('version')
            : $submission->version()->firstOrFail();

        $fields = $this->applicableTo($version, $input);

        if ($fields->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($fields, $input, $submission, $version) {
            $existing = FormAnswer::query()
                ->where('form_submission_id', $submission->getKey())
                ->get()
                ->keyBy(fn (FormAnswer $answer) => $answer->form_field_id.':'.$answer->row_index);

            $changed = [];

            foreach ($fields as $field) {
                if (! array_key_exists($field->key, $input)) {
                    continue;
                }

                if ($field->isTable()) {
                    $changed = array_merge($changed, $this->saveTable(
                        $submission, $version, $field, (array) $input[$field->key], $existing
                    ));

                    continue;
                }

                if (! $this->collectsAnswer($field)) {
                    continue;
                }

                if ($this->put($submission, $field, 0, $input[$field->key], $existing)) {
                    $changed[] = $field->key;
                }
            }

            if ($changed !== []) {
                // The relation the caller is holding is now a lie, and under preventLazyLoading a
                // re-read would hand back the stale copy rather than an error.
                $submission->unsetRelation('answers');
            }

            return $changed;
        });
    }

    /**
     * One repeating table's rows.
     *
     * THE POSTED ROWS ARE THE ROWS. A row the screen no longer carries has been deleted by the
     * person, so its cells go with it — anything else would leave a twelfth bay on an inspection
     * that now lists eleven, visible to exports and invisible on screen.
     *
     * Rows are re-indexed as they are stored, so deleting the second of five rows leaves rows
     * 0..3 rather than a hole at 1 that every later reader has to decide what to do about.
     *
     * @param  array<int|string,mixed>  $rows
     * @param  Collection<string,FormAnswer>  $existing
     * @return array<int,string>
     */
    private function saveTable(
        FormSubmission $submission,
        FormVersion $version,
        FormField $table,
        array $rows,
        Collection $existing,
    ): array {
        $columns = $this->columnsOf($version, $table)
            ->filter(fn (FormField $column) => $this->collectsAnswer($column))
            ->values();

        if ($columns->isEmpty()) {
            return [];
        }

        // A blank row is not a row — see tableRules(). Dropping them here as well as there is what
        // keeps "I typed into three of the five boxes offered" from storing two empty rows.
        $kept = array_values(array_filter(
            $rows,
            fn ($row) => is_array($row) && $this->rowHasContent($row),
        ));

        $changed = false;
        $index = 0;

        foreach ($kept as $row) {
            foreach ($columns as $column) {
                $changed = $this->put(
                    $submission, $column, $index, $row[$column->key] ?? null, $existing
                ) || $changed;
            }

            $index++;
        }

        // Everything beyond the last kept row, for every column of THIS table only.
        $stale = FormAnswer::query()
            ->where('form_submission_id', $submission->getKey())
            ->whereIn('form_field_id', $columns->pluck('id'))
            ->where('row_index', '>=', $index)
            ->delete();

        return ($changed || $stale > 0) ? [$table->key] : [];
    }

    /** @param  array<string,mixed>  $row */
    private function rowHasContent(array $row): bool
    {
        foreach ($row as $value) {
            if (is_array($value) ? $value !== [] : trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Write one cell, or delete it. True when the stored text actually changed.
     *
     * @param  Collection<string,FormAnswer>  $existing
     */
    private function put(
        FormSubmission $submission,
        FormField $field,
        int $rowIndex,
        mixed $value,
        Collection $existing,
    ): bool {
        $stored = $this->normalise($field, $value);
        $before = $existing->get($field->getKey().':'.$rowIndex)?->value;

        if ($stored === $before) {
            return false;
        }

        $match = [
            'form_submission_id' => $submission->getKey(),
            'form_field_id' => $field->getKey(),
            'row_index' => $rowIndex,
        ];

        if ($stored === null) {
            FormAnswer::query()->where($match)->delete();

            return true;
        }

        FormAnswer::query()->updateOrCreate($match, [
            // §74. The submission's, never the request's — and the submission's rather than the
            // actor's, so an answer can never end up filed under a different company from the
            // thing it is an answer to.
            'organization_id' => $submission->getAttribute('organization_id'),
            'value' => $stored,
        ]);

        return true;
    }

    /** One answer, as the text column will hold it — or null, meaning "no answer". */
    private function normalise(FormField $field, mixed $value): ?string
    {
        if ($field->isMultiple()) {
            $chosen = array_values(array_filter(
                array_map(fn ($one) => is_scalar($one) ? trim((string) $one) : '', (array) $value),
                fn (string $one) => $one !== '',
            ));

            return $chosen === [] ? null : (string) json_encode($chosen);
        }

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return match ($field->type) {
            // An unticked box posts '0' and a box nobody has seen posts nothing at all. Only the
            // second is "no answer" — which is why a yes/no question must never be read as No
            // because it was left alone.
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
            // Unreachable through a validated request, reachable from an importer. Refusing to
            // store an unparseable date beats storing a string nothing can read back.
            return null;
        }
    }

    // ================================================================== completeness

    /**
     * The questions that must be answered before this may be submitted, and are not.
     *
     * Validation refuses an incomplete submission on the way in; this exists so the screen can SAY
     * SO BEFORE the person presses the button, which on a fifty-question inspection sheet is the
     * difference between a form and an argument with a form.
     *
     * @return array<int,string> labels, in the order they are asked
     */
    public function missingRequired(FormSubmission $submission): array
    {
        $version = $submission->relationLoaded('version')
            ? $submission->getRelation('version')
            : $submission->version()->firstOrFail();

        $values = $this->valuesFor($submission);
        $missing = [];

        foreach ($this->applicableTo($version, $values) as $field) {
            if ($field->isTable()) {
                $missing = array_merge($missing, $this->missingInTable($version, $field, $values));

                continue;
            }

            if ($field->isSignature()) {
                $missing = array_merge($missing, $this->missingOnSignature($submission, $field, $values));

                continue;
            }

            if (! $field->is_required || ! $this->collectsAnswer($field)) {
                continue;
            }

            $given = $values[$field->key] ?? null;

            if ($given === null || $given === '' || $given === []) {
                $missing[] = $field->label;
            }
        }

        return $missing;
    }

    /**
     * What a required signature block is short of.
     *
     * TWO DIFFERENT FAILURES, AND THEY ARE NOT THE SAME CONVERSATION. Nobody has signed yet, and
     * somebody signed and then the answers moved underneath them. The second is the one worth
     * catching: a permit whose readings were edited after the supervisor signed is a permit nobody
     * actually approved, and it would otherwise submit with a signature block that looks satisfied.
     * Whoever signed is asked to sign again rather than having their name quietly re-used (§28).
     *
     * An optional block is never counted. A form author who adds a signature block and does not
     * tick "required" has said the sheet is complete without it, and this is not the place to
     * overrule them.
     *
     * @param  array<string,mixed>  $values
     * @return array<int,string>
     */
    private function missingOnSignature(FormSubmission $submission, FormField $field, array $values): array
    {
        if (! $field->is_required) {
            return [];
        }

        $signed = $this->signaturesOn($submission, $field);

        if ($signed->isEmpty()) {
            return [$field->label.' — not signed yet'];
        }

        $hash = $this->hashOf($values);

        return $signed->contains(fn (Signature $one) => $one->matches($hash))
            ? []
            : [$field->label.' — the answers changed after this was signed, so it needs signing again'];
    }

    /**
     * What a repeating table is short of.
     *
     * Two different failures, and a person needs to be told which: too few rows for what the author
     * asked for, and a row somebody started and did not finish.
     *
     * @param  array<string,mixed>  $values
     * @return array<int,string>
     */
    private function missingInTable(FormVersion $version, FormField $table, array $values): array
    {
        $rows = is_array($values[$table->key] ?? null) ? $values[$table->key] : [];
        $minimum = (int) $table->setting('min_rows', 0);

        if ($table->is_required && $minimum < 1) {
            $minimum = 1;
        }

        if (count($rows) < $minimum) {
            return [$table->label.' — at least '.$minimum.' '.str('row')->plural($minimum)];
        }

        $missing = [];

        foreach ($rows as $index => $row) {
            foreach ($this->columnsOf($version, $table) as $column) {
                if (! $column->is_required || ! $this->collectsAnswer($column)) {
                    continue;
                }

                $cell = is_array($row) ? ($row[$column->key] ?? null) : null;

                if ($cell === null || $cell === '' || $cell === []) {
                    $missing[] = $table->label.' — row '.($index + 1).', '.strtolower($column->label);
                }
            }
        }

        return $missing;
    }
}
