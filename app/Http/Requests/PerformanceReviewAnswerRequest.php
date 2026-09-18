<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Somebody's answers on a review — either side of it.
 *
 * THE SCORE BOUNDS ARE NOT HERE. They belong to the cycle, which carries its own scale per tenant,
 * so the service checks them against `rating_min`/`rating_max` and this class only refuses what is
 * not a number at all. A `max:5` in this file would be the platform having an opinion about another
 * company's appraisal policy (§47).
 *
 * THE ITEM IDS ARE NOT TRUSTED EITHER. They are keys posted by a browser; the service matches them
 * against the review's own items and ignores anything else, so editing one in the payload reaches
 * nothing (§84, §94).
 */
class PerformanceReviewAnswerRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.self_score' => ['nullable', 'numeric'],
            'items.*.self_comment' => ['nullable', 'string', 'max:2000'],
            'items.*.manager_score' => ['nullable', 'numeric'],
            'items.*.manager_comment' => ['nullable', 'string', 'max:2000'],

            'self_comments' => ['nullable', 'string', 'max:5000'],
            'manager_comments' => ['nullable', 'string', 'max:5000'],
            'overall_rating' => ['nullable', 'numeric'],
            'development_plan' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['self_comments', 'manager_comments', 'overall_rating', 'development_plan']);
    }
}
