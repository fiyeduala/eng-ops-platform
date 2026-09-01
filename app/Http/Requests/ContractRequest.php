<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Contract;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Register a contract, in either direction.
 *
 * ONE FORM FOR BOTH DIRECTIONS, because a contract we hold with a client and a contract we hold with
 * a subcontractor are the same object seen from opposite ends: a value, a duration, a retention
 * percentage, a defects period, and somebody responsible for it. Splitting them would duplicate
 * every rule below and then let the two copies drift.
 *
 * `direction` AND `party_type` MUST AGREE, and the request only checks the shape — that a
 * client-side contract names a client — while ContractService::attachParty() does the real work of
 * proving the id resolves to a record OF THIS TENANT. That split is deliberate: validation can say
 * "this looks wrong", only a scoped Eloquent lookup can say "this is not yours" (§75, §84).
 *
 * `retention_held` IS NOT ACCEPTED. It is a running balance moved by addRetention() and
 * releaseRetention(), which refuse to take it negative. A typed balance is a balance nobody can
 * reconcile.
 *
 * NOR IS `status_id`. A contract becomes active by being executed, which is an Engine 2 transition
 * with a permission behind it, not a select box on the form the commercial assistant fills in.
 *
 * `value` IS NULLABLE, AND THAT IS NOT AN OVERSIGHT. A framework agreement, a call-off arrangement
 * and a schedule-of-rates contract genuinely have no total until work is ordered against them.
 * Requiring a number here would make people type a fictional one, which is worse than a blank.
 *
 * NOT ACCEPTED: `number`, `status_id`, `retention_held`, `organization_id`, `created_by`.
 */
class ContractRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'direction' => ['required', Rule::in(['client', 'vendor'])],

            // The morph, posted as a key and an id. The key is whitelisted here and again in the
            // service; `Relation::getMorphedModel()` is deliberately not used in either place,
            // because it would happily resolve `user` or `project`.
            'party_type' => ['nullable', Rule::in(['client', 'vendor'])],
            'party_id' => ['nullable', 'integer', Rule::requiredIf(fn () => filled($this->input('party_type')))],

            // Kept even when the morph is set, so a terminated supplier removed from the register
            // does not turn every historical contract into "with —".
            'party_name' => ['nullable', 'string', 'max:200'],

            'contract_type' => ['nullable', Rule::in($lists->keys('contract.types', Contract::DEFAULT_TYPES))],
            'reference' => ['nullable', 'string', 'max:100'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'owner_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'document_id' => ['nullable', 'integer', $this->existsInTenant('documents')],

            'value' => ['nullable', 'numeric', 'between:0,9999999999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],

            'retention_percent' => ['nullable', 'numeric', 'between:0,50'],
            'advance_percent' => ['nullable', 'numeric', 'between:0,100'],

            'signed_on' => ['nullable', 'date'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'defects_liability_months' => ['nullable', 'integer', 'between:0,120'],

            'scope_summary' => ['nullable', 'string', 'max:20000'],
            'payment_terms' => ['nullable', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:20000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    /**
     * The one cross-field rule worth stating in words: a contract with a client is not held with a
     * supplier. Getting this wrong points the vendor register at revenue and the client register at
     * spend, and every commercial report downstream inherits the mistake.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $type = $this->input('party_type');
            $direction = $this->input('direction');

            if (filled($type) && filled($direction) && $type !== $direction) {
                $validator->errors()->add(
                    'party_type',
                    $direction === 'client'
                        ? 'A client-side contract has to name a client.'
                        : 'A vendor-side contract has to name a vendor.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'party_type', 'party_id', 'party_name', 'contract_type', 'reference',
            'project_id', 'org_unit_id', 'owner_id', 'document_id', 'value', 'currency',
            'retention_percent', 'advance_percent', 'signed_on', 'starts_on', 'ends_on',
            'defects_liability_months', 'scope_summary', 'payment_terms', 'notes',
        ]);

        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }

    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => 'A contract cannot end before it starts.',
            'party_id.required' => 'Choose the company this contract is with.',
            'currency.size' => 'Use the three-letter currency code — NGN, USD, GBP.',
        ];
    }

    /** @return array<string,mixed> */
    public function contractAttributes(): array
    {
        return $this->validated();
    }
}
