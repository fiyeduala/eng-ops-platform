<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\HseObservation;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * File a safety observation — an unsafe act, an unsafe condition, or something done well.
 *
 * THE SHORTEST FORM IN THE APPLICATION, ON PURPOSE. An observation card that takes four minutes to
 * fill in is an observation card nobody fills in, and a near-miss programme with no cards in it
 * tells a company that its sites are safe right up until they are not. A title and a type is a
 * legitimate observation.
 *
 * `is_anonymous` IS A REAL SWITCH, NOT A DISPLAY ONE. When it is set, HseObservationService leaves
 * `observed_by` null rather than writing it and hiding it — a hidden-but-present column is a promise
 * that lasts exactly until somebody with database access goes looking, and the entire value of the
 * programme rests on people believing the promise. The cost is that an anonymous observation cannot
 * be followed up with its author; that is the trade every scheme worth running has already made.
 *
 * The form therefore does not accept `observed_by` at all. It is the actor, or it is nobody.
 *
 * NOT ACCEPTED: `number`, `status_id`, `observed_by`, `closed_at`, `closed_by`, `organization_id`.
 */
class HseObservationRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'observation_type' => [
                'nullable',
                Rule::in($lists->keys('hse_observation.types', HseObservation::DEFAULT_TYPES)),
            ],
            'category' => [
                'nullable',
                Rule::in($lists->keys('hse_observation.categories', HseObservation::DEFAULT_CATEGORIES)),
            ],
            'risk_level' => [
                'nullable',
                Rule::in($lists->keys('hse_observation.risk_levels', HseObservation::DEFAULT_RISK_LEVELS)),
            ],

            'location' => ['nullable', 'string', 'max:200'],
            'observed_at' => ['nullable', 'date'],

            // Only meaningful on an anonymous card filed on behalf of somebody with no login. The
            // service ignores it when the observation is anonymous.
            'observer_name' => ['nullable', 'string', 'max:200'],
            'is_anonymous' => ['boolean'],

            'immediate_action' => ['nullable', 'string', 'max:10000'],
            'action_required' => ['boolean'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normaliseBooleans(['is_anonymous', 'action_required']);

        $this->nullifyBlanks([
            'description', 'project_id', 'discipline_id', 'org_unit_id', 'observation_type',
            'category', 'risk_level', 'location', 'observed_at', 'observer_name',
            'immediate_action',
        ]);
    }

    /** @return array<string,mixed> */
    public function observationAttributes(): array
    {
        return $this->validated();
    }
}
