<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Engines\Configuration\CustomFieldRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * How a service accepts the fields a company added to its own register (§73).
 *
 * MOST REGISTERS GET THIS FROM RegisterService, which uses this trait. Five do not — Projects,
 * Tasks, Documents, Meetings and Memos were written before that base class existed and each keeps
 * its own create/update. They use the trait directly rather than being rewritten, because the
 * alternative is either five copies of the rules below or a refactor of the five oldest services
 * in the build to make a configuration screen work. A copy of a validation boundary is how one of
 * the copies ends up missing a rule (§84).
 */
trait SavesCustomFields
{
    /**
     * Resolved from the container rather than injected.
     *
     * Forty registers extend RegisterService and thirteen of them declare their own constructor; a
     * fourth required dependency on the base would be a change to thirteen signatures for the sake
     * of purity. The registry is a request singleton whose only state is a memo, so this costs
     * nothing (§89).
     */
    protected function customFields(): CustomFieldRegistry
    {
        return app(CustomFieldRegistry::class);
    }

    /**
     * The custom-field answers this payload carried, checked against their definitions.
     *
     * CREATE ENFORCES EVERY RULE. UPDATE ENFORCES THEM ONLY WHEN THE SECTION WAS POSTED, and the
     * difference is the whole reason a company can make a field required on a register that
     * already holds ten thousand records.
     *
     * On create there is no prior answer, so a required field must be answered or the record would
     * be born invalid. On update the prior answer is already stored: a payload that does not
     * mention the custom-field section is not clearing it and is not being asked about it — a
     * status change, a workflow action, an internal correction. Enforcing `required` there would
     * mean that turning on one checkbox in configuration broke every one of those paths, for every
     * record created before the field existed, with an error message about a field the caller
     * never had the chance to supply.
     *
     * The hole somebody will look for — omitting the section to dodge a required field — exists
     * only on update, where omitting it also means changing nothing. On create, which is where a
     * required field is actually enforced, the rules always run.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function validatedCustomFields(string $entityType, array $attributes, ?Model $record): array
    {
        $posted = $attributes[CustomFieldRegistry::INPUT_KEY] ?? null;

        if ($record !== null && ! is_array($posted)) {
            return [];
        }

        $registry = $this->customFields();

        $input = is_array($posted) ? $posted : [];

        if ($record === null) {
            // Defaults are applied HERE, before validation, so a required field carrying a default
            // is satisfied by it — which is the condition CustomFieldService demands before it
            // lets a field be made required at all.
            $input = $registry->withDefaults($entityType, $input);
        }

        $validated = Validator::make(
            [CustomFieldRegistry::INPUT_KEY => $input],
            $registry->rules($entityType, $record),
            [],
            $registry->attributeNames($entityType),
        )->validate();

        return $validated[CustomFieldRegistry::INPUT_KEY] ?? [];
    }

    /**
     * Strip the custom-field section out of a payload bound for mass assignment.
     *
     * RegisterService already denies the key in safeAttributes(); the five older services fill
     * their models straight from the array they are handed, and an array key that is not a column
     * is a mass-assignment exception waiting for the first company that defines a field.
     *
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    protected function withoutCustomFields(array $attributes): array
    {
        unset($attributes[CustomFieldRegistry::INPUT_KEY]);

        return $attributes;
    }
}
