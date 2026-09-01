<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The hazard table on a risk assessment, posted whole.
 *
 * WHOLESALE REPLACEMENT, NOT ROW-BY-ROW EDITING, and RiskAssessmentService::setHazards() is written
 * the same way. A hazard table is edited as a table — rows are added, reordered, merged and deleted
 * in one sitting — and a per-row API would need an id for every line, which is an id a form can
 * lie about. Sending the whole table means the only thing that has to be authorized is the
 * assessment, which the controller has already done against the bound model.
 *
 * BLANK ROWS ARE DROPPED BY THE SERVICE rather than rejected here. An empty last row is what an
 * "add another" button leaves behind, and failing the whole submission over it would lose the
 * eleven rows somebody did fill in.
 *
 * THE SCORES ARE NOT ACCEPTED. `risk_score` and `residual_score` are computed by the service from
 * likelihood and severity, because a posted score that disagreed with its own factors would make
 * the register's headline number — "how many high risks do we carry" — a number nobody could
 * reproduce (§84).
 */
class RiskAssessmentHazardsRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'hazards' => ['nullable', 'array', 'max:200'],

            'hazards.*.hazard' => ['nullable', 'string', 'max:2000'],
            'hazards.*.who_is_at_risk' => ['nullable', 'string', 'max:300'],
            'hazards.*.existing_controls' => ['nullable', 'string', 'max:5000'],
            'hazards.*.additional_controls' => ['nullable', 'string', 'max:5000'],

            // A five-by-five matrix is overwhelmingly the convention, and the columns are tiny
            // integers. Anything outside it is a typo rather than a house standard.
            'hazards.*.likelihood' => ['nullable', 'integer', 'between:1,5'],
            'hazards.*.severity' => ['nullable', 'integer', 'between:1,5'],
            'hazards.*.residual_likelihood' => ['nullable', 'integer', 'between:1,5'],
            'hazards.*.residual_severity' => ['nullable', 'integer', 'between:1,5'],

            'hazards.*.responsible_id' => ['nullable', 'integer', $this->existsInTenant('users')],
            'hazards.*.target_date' => ['nullable', 'date'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function hazardRows(): array
    {
        return array_values($this->validated()['hazards'] ?? []);
    }
}
