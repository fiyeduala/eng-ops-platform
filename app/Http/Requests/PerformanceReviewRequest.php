<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * What HR may change on a review: who reviews it, and where it is filed.
 *
 * NOTHING ELSE IS HERE, and the absence is the design. Scores, comments, the overall rating and the
 * development plan are written by named acts on the service — `saveSelfAssessment`,
 * `saveManagerReview` — each with its own policy check and its own status guard. A request class
 * that accepted `overall_rating` would be a way to rate somebody without ever being their reviewer
 * (§84).
 *
 * WHO IS BEING REVIEWED IS NOT A FIELD EITHER. It is settled when the cycle opens the review, and
 * the service strips it.
 */
class PerformanceReviewRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'reviewer_id' => ['required', 'integer', $this->existsInTenant('users')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['org_unit_id']);
    }
}
