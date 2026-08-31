<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Deliverable;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Validation\Rule;

/**
 * Add or amend a line on the Master Deliverable Register.
 *
 * A deliverable is a PROMISE, so the required fields are the ones that make a promise checkable:
 * what, for which project, by when. Everything else is optional because a register is routinely
 * loaded from a contract schedule months before anybody knows who will draw it.
 *
 * `planned_issue_on` HAS NO `after_or_equal:today` RULE, deliberately, and it is the one date field
 * in this application that does not. Registers are loaded late — a project three months in still
 * has to record the deliverables it was always contractually due, including the ones already
 * overdue. A rule that refused past dates would force somebody to type a false promise date, and
 * the slippage report is built on that column being true.
 *
 * NOT ACCEPTED: `number`, `status_id`, `progress_percent`, `forecast_issue_on`, `actual_issue_on`,
 * `document_id`, `organization_id`. Progress and issue are separate endpoints with their own
 * permissions — a form that could post `progress_percent` would let anybody who can rename a
 * deliverable also declare it finished (§84), and `actual_issue_on` is the column the schedule
 * report treats as fact.
 */
class DeliverableRequest extends TenantRequest
{
    public function rules(): array
    {
        $types = app(OptionList::class)->keys('engineering.deliverable_types', Deliverable::DEFAULT_TYPES);

        return [
            'title' => ['required', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],

            // Required, unlike every other register here. A deliverable with no project is not a
            // promise anybody can be held to — it is a to-do, and there is a task module for that.
            'project_id' => ['required', 'integer', $this->existsInTenant('projects')],

            'discipline_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            'type' => ['nullable', Rule::in($types)],
            'responsible_id' => ['nullable', 'integer', $this->existsInTenant('users')],

            'planned_start_on' => ['nullable', 'date'],
            'planned_issue_on' => ['nullable', 'date', 'after_or_equal:planned_start_on'],

            'revision' => ['nullable', 'string', 'max:16'],

            // Share of scope, for the weighted progress roll-up. Bounded because a weight of
            // 100000 on one line silently reduces every other deliverable on the project to noise.
            'weight' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],

            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'discipline_id', 'org_unit_id', 'type', 'responsible_id',
            'planned_start_on', 'planned_issue_on', 'revision', 'weight',
        ]);
    }

    public function messages(): array
    {
        return [
            'planned_issue_on.after_or_equal' => 'A deliverable cannot be promised before the work on it starts.',
            'project_id.required' => 'Choose the project this deliverable belongs to.',
        ];
    }

    /** @return array<string,mixed> */
    public function deliverableAttributes(): array
    {
        return $this->validated();
    }
}
