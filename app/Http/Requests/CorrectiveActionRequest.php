<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CorrectiveAction;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Raise or amend a corrective action.
 *
 * THE SOURCE IS NOT ACCEPTED FROM THE BODY. `source_type` and `source_id` are a polymorphic pair,
 * and a polymorphic pair arriving from a form is the classic way to read a record you were never
 * allowed to see: post `source_type=incident` with somebody else's id and the show page renders its
 * title back to you. Actions are attached to their source by CorrectiveActionService::openFrom(),
 * which the workflow calls with a record it already holds, or from the source record's own page
 * where the policy has already authorized it (§84).
 *
 * `assigned_to` IS ACCEPTED HERE BUT REASSIGNMENT IS NOT. Naming an owner while raising the action
 * is ordinary; moving an open action from one person to another is `assign()`, behind
 * `corrective_action.assign`, because it is the act somebody is accountable for and the notification
 * hangs off it. The service's protected list refuses the column on an ordinary update.
 *
 * COMPLETION AND VERIFICATION ARE NOWHERE ON THIS FORM. The assignee says it is done; somebody else
 * says it worked. Two people, two endpoints, two permissions — collapsing them into editable
 * columns is how a register fills up with actions marked complete that changed nothing.
 *
 * NOT ACCEPTED: `number`, `status_id`, `completed_*`, `verified_*`, `effectiveness`,
 * `organization_id`.
 */
class CorrectiveActionRequest extends TenantRequest
{
    public function rules(): array
    {
        $lists = app(OptionList::class);

        return [
            'title' => ['required', 'string', 'max:300'],

            // Required, unlike most descriptions in this application. An action reading "fix
            // scaffold" cannot be verified by anybody, and unverifiable actions are the reason
            // corrective-action registers stop being believed.
            'description' => ['required', 'string', 'max:20000'],

            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'action_type' => [
                'nullable',
                Rule::in($lists->keys('corrective_action.types', CorrectiveAction::DEFAULT_TYPES)),
            ],
            'priority' => [
                'nullable',
                Rule::in($lists->keys('corrective_action.priorities', CorrectiveAction::DEFAULT_PRIORITIES)),
            ],

            'assigned_to' => ['nullable', 'integer', $this->existsInTenant('users')],

            // No `after:today`. Backdating a due date is how a register is brought in line with an
            // action agreed at a meeting last week, and refusing it only produces a wrong date.
            'due_on' => ['nullable', 'date'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'project_id', 'discipline_id', 'org_unit_id', 'action_type', 'priority',
            'assigned_to', 'due_on',
        ]);
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Say what has to be done, in terms somebody else can later confirm was done.',
        ];
    }

    /** @return array<string,mixed> */
    public function correctiveActionAttributes(): array
    {
        return $this->validated();
    }
}
