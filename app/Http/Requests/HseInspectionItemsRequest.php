<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * The checklist on an HSE inspection, posted whole.
 *
 * SAME SHAPE AS THE HAZARD TABLE, and for the same reasons — see RiskAssessmentHazardsRequest.
 *
 * `score_possible` IS SET WHEN THE CHECKLIST IS WRITTEN AND `score_achieved` IS NOT. What an item
 * is worth is a property of the question; what it scored is a property of the walk. Keeping them on
 * different endpoints is what stops somebody re-weighting the questions after seeing the answers,
 * which is the only way a compliance percentage can be made to say whatever is wanted.
 *
 * Nothing here is required. An inspection with no checklist is a legitimate thing to schedule —
 * plenty of site walks are written up as a narrative — and the score simply stays null, which the
 * model reports as "not scored" rather than as zero.
 */
class HseInspectionItemsRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:300'],

            'items.*.reference' => ['nullable', 'string', 'max:60'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.category' => ['nullable', 'string', 'max:60'],
            'items.*.score_possible' => ['nullable', 'numeric', 'between:0,999999'],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function itemRows(): array
    {
        return array_values($this->validated()['items'] ?? []);
    }
}
