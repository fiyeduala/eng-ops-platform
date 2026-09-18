<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ReviewCycleCompetency;
use Illuminate\Validation\Rule;

/**
 * An appraisal round and the questions it asks.
 *
 * THE RATING SCALE IS COMPANY DATA. A company that rates out of four is not wrong, and neither is
 * one that rates 0–10; the rules here only check that the top is above the bottom and that the range
 * is small enough to be usable on a form. Everything else — what a 3 means, whether a 5 needs
 * justification — belongs to the company, not to the platform (§47).
 *
 * THE COMPETENCIES ARRIVE AS ROWS and are synced by the service, which refuses to touch them once
 * reviews exist. Validating them here keeps a blank label out of a list somebody has to score.
 */
class ReviewCycleRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'opens_on' => ['nullable', 'date'],
            'closes_on' => ['nullable', 'date', 'after_or_equal:opens_on'],
            'rating_min' => ['required', 'numeric', 'min:0', 'max:1000'],
            'rating_max' => ['required', 'numeric', 'gt:rating_min', 'max:1000'],
            'instructions' => ['nullable', 'string', 'max:5000'],

            'competencies' => ['nullable', 'array', 'max:60'],
            'competencies.*.id' => ['nullable', 'integer'],
            'competencies.*.label' => ['required_with:competencies', 'string', 'max:200'],
            'competencies.*.description' => ['nullable', 'string', 'max:2000'],
            'competencies.*.kind' => ['nullable', Rule::in(ReviewCycleCompetency::KINDS)],
            'competencies.*.weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'competencies.*.org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'competencies.*.position_id' => ['nullable', 'integer', $this->existsInTenant('positions')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['description', 'opens_on', 'closes_on', 'instructions']);
    }
}
