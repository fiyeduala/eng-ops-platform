<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\HseInspection;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Schedule or amend an HSE inspection.
 *
 * THIS FORM PLANS A WALK; IT DOES NOT RECORD ONE. `performed_at`, `performed_by` and both score
 * columns are written by HseInspectionService::perform() from the checklist, and the service
 * refuses a second walk. A form that accepted a score directly would let somebody type "94%"
 * without answering a single item, which is precisely the number the register exists to make
 * impossible (§84).
 *
 * THE CHECKLIST IS SEPARATE. It is a table, posted whole, through HseInspectionItemsRequest, and it
 * is frozen once the inspection has been carried out — changing the questions after seeing the
 * answers is how a compliance figure becomes fiction.
 *
 * NOT ACCEPTED: `number`, `status_id`, `performed_*`, `score_*`, `summary`, `closed_*`,
 * `organization_id`.
 */
class HseInspectionRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'inspection_type' => [
                'nullable',
                Rule::in(app(OptionList::class)->keys('hse_inspection.types', HseInspection::DEFAULT_TYPES)),
            ],

            'location' => ['nullable', 'string', 'max:200'],
            'scheduled_for' => ['nullable', 'date'],

            // Free text rather than a user id: the person walking round with you is very often a
            // subcontractor's foreman or a client representative with no account here.
            'accompanied_by' => ['nullable', 'string', 'max:200'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'project_id', 'discipline_id', 'org_unit_id', 'inspection_type',
            'location', 'scheduled_for', 'accompanied_by',
        ]);
    }

    /** @return array<string,mixed> */
    public function inspectionAttributes(): array
    {
        return $this->validated();
    }
}
