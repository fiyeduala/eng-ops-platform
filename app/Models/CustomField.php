<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing a company decided to record that the platform did not think of (§73).
 *
 * THE TYPE IS CONFIGURATION, NOT STRUCTURE. Every value is stored as text and cast on the way out
 * by the type declared here — see the migration for the argument. That means changing a field from
 * text to number is a configuration change rather than a data migration, and it also means the
 * type is the ONLY thing standing between a text column and whatever somebody typed. The
 * validation compiler in CustomFieldRegistry is therefore not a convenience; it is the boundary.
 */
class CustomField extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    /**
     * The field types a company may choose from.
     *
     * DELIBERATELY SHORT. Each one costs a validation rule, a form control, a display format and a
     * filter behaviour, and every type that is nearly the same as another is a question an
     * administrator has to answer for no benefit. "Email" and "phone" are text with a pattern;
     * "currency" is a number the company's currency setting already formats.
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
    ];

    /** Types whose answers come from an option list. */
    public const CHOICE_TYPES = ['select', 'multiselect'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_filterable' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function isChoice(): bool
    {
        return in_array($this->type, self::CHOICE_TYPES, true);
    }

    public function isMultiple(): bool
    {
        return $this->type === 'multiselect';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Whether this field applies to a particular record.
     *
     * A field may be narrowed to one kind of record — "Concession number, but only on offshore
     * projects". The comparison is a string one on purpose: the attribute being matched may be an
     * enum, an id or a status key, and a loose comparison here would make 0 match 'draft'.
     */
    public function appliesTo(?Model $record): bool
    {
        if ($this->applies_when_key === null || $this->applies_when_key === '') {
            return true;
        }

        if ($record === null) {
            // On a blank create form there is nothing to compare against yet. Showing the field is
            // the safe failure: a field offered where it does not apply is a question somebody can
            // leave blank, while a field hidden where it does apply is data silently not captured.
            return true;
        }

        return (string) $record->getAttribute($this->applies_when_key) === (string) $this->applies_when_value;
    }

    public function scopeForEntity(Builder $query, string $entityType): Builder
    {
        return $query->where('entity_type', $entityType);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('label');
    }

    // ------------------------------------------------------------------ access hooks

    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return [];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /**
     * Reading a field DEFINITION is company-wide; reading a field VALUE is not.
     *
     * The label "Client cost code" has to render on every record that carries it, for everybody
     * who may see that record, or the form is a row of unlabelled boxes. What was typed into it is
     * governed entirely by the record it hangs off — see HasCustomFields, which never loads values
     * except through a record the viewer has already been granted.
     */
    public function accessOrganizationWideAbilities(): array
    {
        return ['custom_field.view'];
    }
}
