<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\QualityInspection;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Call an inspection.
 *
 * THE ITP REFERENCE IS THE FIELD THAT MAKES THIS RECORD AUDITABLE. An inspection request that does
 * not say which line of which inspection and test plan it discharges cannot be reconciled against
 * the plan at handover, and reconciling them is the entire point of keeping the register. It is
 * nullable because plenty of inspections are called outside any ITP; it is asked for first because
 * most are not.
 *
 * THE RESULT IS NOT ON THIS FORM. `result`, `inspected_at` and `inspected_by` are written by
 * QualityInspectionService::inspect(), which refuses a second verdict and refuses to let the person
 * who requested the inspection record its outcome. Nobody inspects their own work — that rule is
 * enforced in the service as well as the policy, so it holds for a workflow auto-action and an
 * import just as it does for this form (§84).
 *
 * THE CHECKPOINTS ARE SEPARATE, posted whole through QualityInspectionItemsRequest, and frozen once
 * the inspection has taken place.
 *
 * NOT ACCEPTED: `number`, `status_id`, `inspected_*`, `result`, `comments`, `organization_id`.
 */
class QualityInspectionRequest extends TenantRequest
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
                Rule::in(app(OptionList::class)->keys('quality_inspection.types', QualityInspection::DEFAULT_TYPES)),
            ],

            'itp_reference' => ['nullable', 'string', 'max:120'],
            'activity' => ['nullable', 'string', 'max:300'],
            'location' => ['nullable', 'string', 'max:200'],

            'scheduled_for' => ['nullable', 'date'],
            'required_by' => ['nullable', 'date'],

            // Free text: the client's representative or the third-party surveyor who witnesses the
            // test usually has no account in this system.
            'witnessed_by' => ['nullable', 'string', 'max:200'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'project_id', 'discipline_id', 'org_unit_id', 'inspection_type',
            'itp_reference', 'activity', 'location', 'scheduled_for', 'required_by', 'witnessed_by',
        ]);
    }

    /** @return array<string,mixed> */
    public function inspectionAttributes(): array
    {
        return $this->validated();
    }
}
