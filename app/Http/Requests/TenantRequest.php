<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Base for every tenant-scoped form request.
 *
 * It exists for one reason, and it is the reason §84 gives: a foreign key arriving in a form is
 * user input. `exists:org_units,id` is a QUERY BUILDER rule — it does not go through Eloquent, so
 * the tenant global scope never touches it. Left alone, a POST carrying another company's
 * org_unit_id would validate cleanly and be written to our row, quietly stitching two tenants
 * together through a foreign key. Every relational rule in this application therefore goes
 * through existsInTenant(), which pins the lookup to the actor's own organization.
 *
 * The organization itself is NEVER read from the request. It comes from the authenticated user,
 * the same source EnsureOrganizationContext uses, so a hidden field named organization_id is
 * inert no matter what it contains.
 */
abstract class TenantRequest extends FormRequest
{
    /**
     * Authorization lives in the controller, against the bound record, because that is the only
     * place the record exists. A FormRequest can answer "may this kind of user do this"; only a
     * policy call with the model in hand can answer "may they do it to THIS row" (Gates 3–5).
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function organizationId(): int
    {
        return (int) $this->user()->organization_id;
    }

    /**
     * "This id must exist AND belong to us AND not be in the recycle bin."
     */
    protected function existsInTenant(string $table, string $column = 'id', bool $activeOnly = false): Exists
    {
        return Rule::exists($table, $column)
            ->where(function ($query) use ($table, $activeOnly) {
                $query->where('organization_id', $this->organizationId());

                if ($this->hasSoftDeletes($table)) {
                    $query->whereNull('deleted_at');
                }

                if ($activeOnly) {
                    $query->where('is_active', true);
                }
            });
    }

    /**
     * Uniqueness is per organization, never global — two companies may both have a department
     * coded "ENG", and a global unique index would leak the other tenant's existence through a
     * validation message.
     */
    protected function uniqueInTenant(string $table, string $column, int|string|null $ignore = null): Unique
    {
        $rule = Rule::unique($table, $column)
            ->where(fn ($query) => $query->where('organization_id', $this->organizationId()));

        return $ignore !== null ? $rule->ignore($ignore) : $rule;
    }

    /**
     * Unchecked checkboxes are absent from a POST body entirely, so a rule of `boolean` on a
     * missing key passes while the value silently stays whatever it was. Normalising them here
     * means "I unticked Active" actually means false.
     *
     * @param  array<int,string>  $keys
     */
    protected function normaliseBooleans(array $keys): void
    {
        $merge = [];

        foreach ($keys as $key) {
            $merge[$key] = $this->boolean($key);
        }

        $this->merge($merge);
    }

    /**
     * Blank optional selects arrive as "" and would be written as an empty string into a
     * nullable foreign key column.
     *
     * @param  array<int,string>  $keys
     */
    protected function nullifyBlanks(array $keys): void
    {
        $merge = [];

        foreach ($keys as $key) {
            if ($this->has($key) && $this->input($key) === '') {
                $merge[$key] = null;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    private function hasSoftDeletes(string $table): bool
    {
        return in_array($table, [
            'org_units', 'employees', 'positions', 'locations', 'users', 'roles',
        ], true);
    }
}
