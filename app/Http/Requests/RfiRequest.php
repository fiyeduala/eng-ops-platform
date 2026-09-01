<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Enums\Confidentiality;
use App\Support\Enums\RfiDirection;
use App\Support\Enums\RfiKind;
use Illuminate\Validation\Rule;

/**
 * Raise or amend a request for information, technical query or site query.
 *
 * THE QUESTION IS REQUIRED AND THE ANSWER IS NOT ACCEPTED. An RFI with a subject and no question is
 * a calendar reminder; the whole record exists to carry a precise question with a date on it. The
 * answer arrives through `respond()`, which has its own permission — `rfi.respond` is not
 * `rfi.edit`, because answering a technical query commits the organization to a position and
 * renaming one does not (§84).
 *
 * `kind` AND `direction` ARE SET ONCE AND MATTER PERMANENTLY. `kind` chooses which numbering series
 * the record draws from, so changing it later would leave an RFI carrying a TQ number; the
 * controller therefore refuses to change it after creation. `direction` decides whose deadline this
 * is — outgoing means somebody else owes us, incoming means the clock runs against us — and mixing
 * them is how a company ends up chasing a client for forty answers while sitting on thirty of its
 * own.
 *
 * `required_by` IS THE CONTRACTUAL DATE and is nudged, not enforced: the settings screen holds a
 * default response time which pre-fills the field, and anybody may type something else, because the
 * contract governs and the setting is only a habit.
 *
 * NOT ACCEPTED: `number`, `status_id`, `answer`, `answered_by`, `answered_at`, `organization_id`.
 */
class RfiRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:300'],
            'question' => ['required', 'string', 'max:20000'],

            'kind' => ['required', Rule::enum(RfiKind::class)],
            'direction' => ['required', Rule::enum(RfiDirection::class)],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // The other side. FREE TEXT REMAINS THE PRIMARY FIELD even now that clients and vendors
            // have tables: an RFI goes to a regulator, a joint-venture partner or a specialist
            // consultant just as often as it goes to somebody already on a register, and a form
            // that insists on a link is a form people work around.
            'party_name' => ['nullable', 'string', 'max:200'],

            // The optional link. Whitelisted here and again in ResolvesParties, which resolves the
            // id through a tenant-scoped query — `Rule::in` proves the shape, only the lookup can
            // prove the row is ours (§75, §84).
            'party_type' => ['nullable', Rule::in(['client', 'vendor'])],
            'party_id' => ['nullable', 'integer', 'required_with:party_type'],

            // Who is asking, on the record. Often not the person typing: a document controller logs
            // the query an engineer wrote, and the engineer is the one who has to defend it.
            'raised_by' => ['nullable', 'integer', $this->existsInTenant('users')],
            'raised_on' => ['nullable', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:raised_on'],

            // Flags, not amounts. At the moment an RFI is raised nobody knows the number, and a
            // nullable amount invites a guess into a column that later gets summed — the money
            // belongs on the change request the answer provokes.
            'cost_impact' => ['boolean'],
            'schedule_impact' => ['boolean'],
            'impact_notes' => ['nullable', 'string', 'max:2000'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['cost_impact', 'schedule_impact']);
        $this->nullifyBlanks([
            'project_id', 'discipline_id', 'org_unit_id', 'party_name', 'party_type', 'party_id',
            'raised_by', 'raised_on', 'required_by', 'impact_notes',
        ]);
    }

    public function messages(): array
    {
        return [
            'question.required' => 'Write the question. An RFI without one cannot be answered.',
            'required_by.after_or_equal' => 'The answer cannot be due before the question was asked.',
        ];
    }

    /** @return array<string,mixed> */
    public function rfiAttributes(): array
    {
        return $this->validated();
    }
}
