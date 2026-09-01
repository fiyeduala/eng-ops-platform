<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Submittal;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Raise or amend a submittal — a package sent for someone else's review.
 *
 * A submittal is a CONTAINER. The header identifies the package and the party reviewing it; what is
 * actually being reviewed lives in the items, each pointing at a document revision, added through
 * their own endpoint so each one passes the document's own policy on the way in (§67). A submittal
 * with no items cannot be submitted, and the service refuses it rather than this form, because the
 * items do not exist yet when this form is first posted.
 *
 * THE REVIEW OUTCOME IS NOT ON THIS FORM. `review_code`, `review_comment`, `reviewed_by` and
 * `reviewed_at` are written by `review()`, behind `submittal.review`. This is the security-relevant
 * half of the module: a form that could post a review code would let the party being reviewed stamp
 * their own package approved (§84).
 *
 * NOT ACCEPTED: `number`, `status_id`, `review_code`, `review_comment`, `reviewed_by`,
 * `reviewed_at`, `resubmittal_of_id`, `organization_id`. Resubmittal linkage is set by `resubmit()`,
 * which copies a package forward — a posted `resubmittal_of_id` could otherwise point a fresh
 * submittal at somebody else's rejected one and inherit its history.
 */
class SubmittalRequest extends TenantRequest
{
    public function rules(): array
    {
        $types = app(OptionList::class)->keys('engineering.submittal_types', Submittal::DEFAULT_TYPES);

        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],

            'type' => ['nullable', Rule::in($types)],
            'revision' => ['nullable', 'string', 'max:16'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // Who is reviewing it. Free text plus an optional link — see RfiRequest for the same
            // reasoning, and ResolvesParties for why the id is looked up rather than trusted.
            'party_name' => ['nullable', 'string', 'max:200'],
            'party_type' => ['nullable', Rule::in(['client', 'vendor'])],
            'party_id' => ['nullable', 'integer', 'required_with:party_type'],

            'submitted_by' => ['nullable', 'integer', $this->existsInTenant('users')],
            'submitted_on' => ['nullable', 'date'],
            'required_by' => ['nullable', 'date', 'after_or_equal:submitted_on'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'type', 'revision', 'project_id', 'discipline_id', 'org_unit_id',
            'party_name', 'party_type', 'party_id', 'submitted_by', 'submitted_on', 'required_by',
        ]);
    }

    public function messages(): array
    {
        return [
            'required_by.after_or_equal' => 'A review cannot be due before the package was submitted.',
        ];
    }

    /** @return array<string,mixed> */
    public function submittalAttributes(): array
    {
        return $this->validated();
    }
}
