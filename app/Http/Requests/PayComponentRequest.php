<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PayComponent;
use Illuminate\Validation\Rule;

/**
 * One rule in the company's pay structure.
 *
 * THE VALIDATION IS STRUCTURAL, NOT FISCAL. It checks that a banded component has bands and that a
 * percentage has something to be a percentage OF. It does not check that a rate is plausible, that
 * a tax table matches any country's law, or that the bands add up to anything in particular —
 * because the platform has no standing to hold an opinion about any of that (§47), and a rule here
 * that "helpfully" rejected an unusual rate would be the platform refusing to let a company obey its
 * own tax authority.
 *
 * THE ONE THING IT IS STRICT ABOUT IS INCOHERENCE. A percentage of nothing, or a band table with no
 * bands, produces zero on every payslip in the company, silently, and the first person to notice is
 * whoever is owed the money.
 */
class PayComponentRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],

            'kind' => ['required', Rule::in(array_keys(PayComponent::KINDS))],
            'method' => ['required', Rule::in(array_keys(PayComponent::METHODS))],
            'basis' => ['required', Rule::in(array_keys(PayComponent::BASES))],

            'rate' => ['nullable', 'numeric', 'min:0'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'minimum_amount' => ['nullable', 'numeric', 'min:0'],
            'maximum_amount' => ['nullable', 'numeric', 'min:0', 'gte:minimum_amount'],

            'is_taxable' => ['boolean'],
            'is_pensionable' => ['boolean'],
            'reduces_taxable' => ['boolean'],
            'is_prorated' => ['boolean'],
            'applies_to_all' => ['boolean'],
            'is_active' => ['boolean'],
            'sequence' => ['required', 'integer', 'min:0', 'max:65535'],

            'bands' => ['array'],
            'bands.*.from_amount' => ['required_with:bands.*.rate', 'nullable', 'numeric', 'min:0'],
            // Blank means "and everything above", which is what the top band of every progressive
            // table says. A required upper limit would make the top band unexpressible.
            'bands.*.to_amount' => ['nullable', 'numeric', 'gte:bands.*.from_amount'],
            'bands.*.rate' => ['required_with:bands.*.from_amount', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $method = $this->input('method');
            $basis = $this->input('basis');

            // A percentage of nothing is zero for everybody, every month, and nothing says so.
            if (in_array($method, ['percent', 'banded'], true) && $basis === 'none') {
                $validator->errors()->add('basis', 'A percentage or banded component needs something to be calculated on.');
            }

            if ($method === 'banded' && collect($this->input('bands', []))->filter(fn ($b) => filled($b['rate'] ?? null))->isEmpty()) {
                $validator->errors()->add('bands', 'A banded component needs at least one band.');
            }

            // Hourly pricing reads the hours from the overtime register; the basis column plays no
            // part, and a company that set it to "gross" would be describing something that cannot
            // happen.
            if ($method === 'hourly' && $basis !== 'overtime_hours') {
                $validator->errors()->add('basis', 'An hourly component is calculated on approved overtime hours.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['description', 'rate', 'amount', 'minimum_amount', 'maximum_amount']);

        $this->merge([
            'is_taxable' => $this->boolean('is_taxable'),
            'is_pensionable' => $this->boolean('is_pensionable'),
            'reduces_taxable' => $this->boolean('reduces_taxable'),
            'is_prorated' => $this->boolean('is_prorated'),
            'applies_to_all' => $this->boolean('applies_to_all'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
