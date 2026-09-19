<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Form definitions — the form, its versions, its sections and its questions (§73).
 *
 * READING IS BASELINE, for the reason CustomFieldPolicy gives: somebody who may fill a form in has
 * to be able to read the questions, and a person who could not would be served a form missing the
 * parts their company considers mandatory and then refused on save by a rule they were never
 * shown. Reading a DEFINITION is not reading a SUBMISSION — those are governed by
 * FormSubmissionPolicy, which carries the project and department axes.
 *
 * `publish` IS SEPARATE FROM `manage`, and that is the whole point of this policy rather than a
 * copy of the custom-field one. Building a draft is drafting; publishing it makes it the form every
 * site supervisor in the company fills in tomorrow and freezes it as the thing their answers will
 * be read against. Plenty of companies want a safety officer who can draft an inspection sheet and
 * a manager who signs it off, and a system that cannot express that forces them to grant the
 * stronger permission to everybody who needs the weaker one.
 *
 * Versions, sections and fields all answer here, through accessParentRelation — a question that
 * could be read or edited on its own terms would be a way round the form's own gate (§94).
 */
class FormPolicy extends ScopedPolicy
{
    protected string $module = 'form';

    /** @var array<string,string> */
    protected array $abilityMap = [
        'viewAny' => 'view',
        'view' => 'view',
        'create' => 'create',
        'update' => 'edit',
        'delete' => 'delete',
        'restore' => 'edit',
        'forceDelete' => 'delete_permanently',
    ];

    /**
     * Make this edition the one people fill in, and freeze it.
     *
     * Deliberately not mapped through `manage`: see the class docblock.
     */
    public function publish(User $user, Model $record): bool
    {
        return $this->check($user, 'publish', $record);
    }

    /**
     * Take a live form out of service.
     *
     * The same authority as publishing, because it is the same decision in the other direction —
     * somebody who may decide which form the company uses may also decide it stops using one.
     */
    public function retire(User $user, Model $record): bool
    {
        return $this->check($user, 'publish', $record);
    }
}
