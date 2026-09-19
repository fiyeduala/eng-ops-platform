<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Engines\Configuration\CustomFieldRegistry;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A register that accepts fields its company invented (§73).
 *
 * THE ONLY DOOR TO A VALUE. Values have no policy and no scope of their own; they are reachable
 * through this trait, on a record the access engine has already granted, and nowhere else. That is
 * what makes "can this person read this answer" a question with exactly one answer (§6).
 *
 * THE N+1 IS THE WHOLE COST OF THIS DESIGN, so the eager-load is a first-class member of the trait
 * rather than something each listing has to remember: `Project::withCustomFields()->paginate()`
 * loads every answer on the page in one query (§89). Under preventLazyLoading a listing that
 * forgets will fail loudly in development, which is the intended teacher.
 *
 * A model using this trait must also appear in CustomFieldCatalogue, or an administrator will
 * never be offered the register to add a field to. The catalogue is the statement that the whole
 * path — define, render, validate, save, display — works for that register.
 */
trait HasCustomFields
{
    /**
     * Every answer this record has stored.
     *
     * The morph key is the register's catalogue name, via the morph map — 'hse_incident', never a
     * class name, so renaming a class does not orphan a table full of values.
     */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'subject');
    }

    /** Load a page of records' answers in one query rather than one per record (§89). */
    public function scopeWithCustomFields(Builder $query): Builder
    {
        return $query->with('customFieldValues');
    }

    /**
     * The answers, keyed by field, cast to the types their definitions declare.
     *
     * Named "answers" rather than "values" because customFieldValues() is the relation and two
     * methods a character apart is how somebody eventually calls the wrong one.
     *
     * @return array<string,mixed>
     */
    public function customFieldAnswers(): array
    {
        return app(CustomFieldRegistry::class)->valuesFor($this);
    }

    /**
     * One answer, by field key — null when the question was never answered.
     *
     * Deliberately resolves through the registry rather than reading the relation directly, so
     * that a caller asking for a "number" field gets a number and not the text it was stored as.
     */
    public function customField(string $key): mixed
    {
        return app(CustomFieldRegistry::class)->valuesFor($this)[$key] ?? null;
    }

    /**
     * The answers arranged for a show page: definition, value and the text to print.
     *
     * @return array<int,array{field:CustomField,value:mixed,display:string}>
     */
    public function customFieldsForDisplay(): array
    {
        return app(CustomFieldRegistry::class)->displayFor($this);
    }
}
