<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question on one edition of a form.
 *
 * THE TYPE IS THE VALIDATION BOUNDARY, exactly as it is for custom fields, and for the same reason:
 * every answer lands in one text column, so what the column is allowed to contain is decided here
 * and compiled server-side. A `config` blob rides along for per-type settings, and NOTHING IN IT
 * CAN LOOSEN A RULE — the compiler reads `type`, `is_required` and `option_list_key`, which are
 * columns, and treats config as presentation only (§84).
 *
 * FOUR TYPES DO SOMETHING CUSTOM FIELDS HAVE NO EQUIVALENT OF:
 *
 *   'table'     — a repeating group. Its child fields are the columns; answers carry a row_index.
 *                 One level deep only. A table inside a table is a spreadsheet, and a form builder
 *                 that grows one has stopped being a form builder and started being a bad Excel.
 *
 *   'signature' — an approval block. Answers nothing itself; it is the anchor a Signature is
 *                 written against, reusing the same written-once, hash-bearing signature the
 *                 document module uses rather than inventing a second one with weaker rules.
 *
 *   'heading'   — a subheading inside a section, for a form long enough to need them.
 *   'note'      — standing text: an instruction, a warning, a regulation reference. Asks nothing.
 *
 * The last three are why `answersSomething()` exists. A field that asks nothing must never be
 * counted as unanswered, never be made required, and never appear in an export as an empty column.
 */
class FormField extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /**
     * What a form may ask.
     *
     * The custom-field set, plus the four above. Kept deliberately short for the same reason: every
     * type costs a rule, a control, a display format and an export behaviour, and two types that
     * are nearly the same are a decision an author has to make for no benefit.
     *
     * @var array<string,string>
     */
    public const TYPES = [
        'text' => 'Short text',
        'textarea' => 'Long text',
        'number' => 'Number',
        'date' => 'Date',
        'boolean' => 'Yes / no',
        'select' => 'Choose one',
        'multiselect' => 'Choose several',
        'table' => 'Repeating table',
        'signature' => 'Signature block',
        'heading' => 'Subheading',
        'note' => 'Standing text',
    ];

    /** Types whose answers come from an option list. */
    public const CHOICE_TYPES = ['select', 'multiselect'];

    /** Types that display something and collect nothing. */
    public const STATIC_TYPES = ['heading', 'note'];

    /** Types that may not be a column inside a repeating table. */
    public const NOT_IN_TABLE = ['table', 'signature'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_required' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public static function permissionModule(): string
    {
        return 'form';
    }

    // ------------------------------------------------------------------ relationships

    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(FormSection::class, 'form_section_id');
    }

    /** The repeating table this field is a column of, when it is one. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_field_id');
    }

    /** The columns, when this field is a repeating table. */
    public function columns(): HasMany
    {
        return $this->hasMany(self::class, 'parent_field_id')->orderBy('sort')->orderBy('id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
    }

    // ------------------------------------------------------------------ kind

    public function isChoice(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    public function isMultiple(): bool
    {
        return $this->type === 'multiselect';
    }

    public function isTable(): bool
    {
        return $this->type === 'table';
    }

    public function isSignature(): bool
    {
        return $this->type === 'signature';
    }

    public function isColumn(): bool
    {
        return $this->parent_field_id !== null;
    }

    /**
     * Whether this field collects an answer at all.
     *
     * A heading and a note display; a table is a container whose columns collect. Anything that
     * answers nothing must not be required, must not be counted as missing, and must not become an
     * empty column in an export — three separate bugs that all come from one missing check.
     */
    public function answersSomething(): bool
    {
        return ! in_array($this->type, self::STATIC_TYPES, true) && ! $this->isTable();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function displayName(): string
    {
        return (string) $this->label;
    }

    /**
     * A per-type setting, or a fallback.
     *
     * Presentation only. Nothing read through here may widen what the field accepts — see the
     * class docblock.
     */
    public function setting(string $key, mixed $fallback = null): mixed
    {
        return $this->config[$key] ?? $fallback;
    }

    /**
     * Whether this question is asked of a particular set of answers.
     *
     * The same narrowing custom fields have — "only when the answer to `has_permit` is no" — except
     * that what it compares against is the other answers on the same form rather than a column on a
     * record. A string comparison on purpose: 0 must not match 'draft'.
     *
     * @param  array<string,mixed>  $answers  keyed by field key
     */
    public function appliesTo(array $answers): bool
    {
        if ($this->applies_when_key === null || $this->applies_when_key === '') {
            return true;
        }

        if (! array_key_exists($this->applies_when_key, $answers)) {
            // The question it depends on has not been answered. Showing the field is the safe
            // failure, for the reason CustomField::appliesTo() gives: a question offered where it
            // does not apply can be left blank, while one hidden where it does apply is data
            // silently not captured.
            return true;
        }

        $given = $answers[$this->applies_when_key];

        return is_array($given)
            ? in_array((string) $this->applies_when_value, array_map('strval', $given), true)
            : (string) $given === (string) $this->applies_when_value;
    }

    // ------------------------------------------------------------------ access hooks

    public function accessParentRelation(): ?string
    {
        return 'version';
    }

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    public function accessOwnerColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function accessOrganizationWideAbilities(): array
    {
        return ['form.view'];
    }
}
