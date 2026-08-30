<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Task;
use App\Support\Enums\Confidentiality;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit a task (§16).
 *
 * The same three absences as ProjectRequest, for the same reasons: `number` is issued by the
 * numbering engine, `status_id` moves through StatusMachine's whitelist rather than a select, and
 * `organization_id` comes from the session so a hidden field of that name is inert.
 *
 * `assignee_id` is here on CREATE only, and its presence is not the authorization. Writing that
 * column is a visibility grant, so the controller re-resolves the candidate from the validated
 * payload and asks TaskPolicy::assign() with both the task and the person in hand. On EDIT the
 * field is absent entirely: assignment has its own route, its own policy question and its own
 * event, and letting it ride in on the general edit form would be a way around the candidate
 * check (§84).
 *
 * Every foreign key goes through existsInTenant(), which is what stops a POST carrying another
 * company's project_id from validating cleanly and stitching two tenants together (§75).
 */
class TaskRequest extends TenantRequest
{
    public function rules(): array
    {
        $task = $this->task();

        return [
            // --- identity -----------------------------------------------------------------
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],

            // --- placement ----------------------------------------------------------------
            // Nullable on purpose: plenty of real work belongs to a department and no project.
            'project_id' => ['nullable', 'integer', $this->existsInTenant('projects')],
            'parent_id' => ['nullable', 'integer', $this->existsInTenant('tasks')],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],

            // Create only. See the class docblock.
            'assignee_id' => $task !== null
                ? ['nullable']
                : ['nullable', 'integer', $this->existsInTenant('users', 'id', true)],

            // --- sensitivity --------------------------------------------------------------
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'priority' => ['required', Rule::in(self::priorities())],

            // --- schedule -----------------------------------------------------------------
            'start_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date', 'after_or_equal:start_on'],

            // --- effort -------------------------------------------------------------------
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'actual_hours' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'progress_percent' => ['nullable', 'integer', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $task = $this->task();

            // The service walks the whole ancestor chain; this catches the obvious half early so
            // the message names the field the user is looking at rather than arriving as a
            // DomainException on the next screen.
            if ($task !== null
                && $this->filled('parent_id')
                && (int) $this->input('parent_id') === (int) $task->getKey()) {
                $validator->errors()->add('parent_id', 'A task cannot be its own parent.');
            }

            // A subtask that belongs to a different project than its parent produces a task that
            // appears on one project board and rolls up into another — the kind of inconsistency
            // that is invisible until someone reports on it.
            if ($this->filled('parent_id')) {
                $parent = Task::query()->whereKey((int) $this->input('parent_id'))->first();

                $parentProject = $parent?->project_id !== null ? (int) $parent->project_id : null;
                $ownProject = $this->filled('project_id') ? (int) $this->input('project_id') : null;

                if ($parent !== null && $parentProject !== null && $parentProject !== $ownProject) {
                    $validator->errors()->add(
                        'parent_id',
                        'A subtask must belong to the same project as its parent task.'
                    );
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'description', 'project_id', 'parent_id', 'org_unit_id', 'assignee_id',
            'start_on', 'due_on', 'estimated_hours', 'actual_hours', 'progress_percent',
        ]);

        $this->merge([
            'confidentiality' => $this->input('confidentiality') ?: Confidentiality::Internal->value,
            'priority' => $this->input('priority') ?: 'normal',
        ]);
    }

    /**
     * The validated payload with the fields the service owns removed.
     *
     * `assignee_id` never travels this way: it is read separately by the controller, authorized as
     * a grant, and applied through TaskService::assign() so the assignment is stamped and the
     * event fires.
     *
     * @return array<string,mixed>
     */
    public function taskAttributes(): array
    {
        $data = $this->validated();

        unset($data['assignee_id']);

        return $data;
    }

    /** @return array<int,string> */
    public static function priorities(): array
    {
        return ['low', 'normal', 'high', 'critical'];
    }

    public function attributes(): array
    {
        return [
            'project_id' => 'project',
            'parent_id' => 'parent task',
            'org_unit_id' => 'department',
            'assignee_id' => 'assignee',
            'start_on' => 'start date',
            'due_on' => 'due date',
            'estimated_hours' => 'estimated hours',
            'actual_hours' => 'actual hours',
            'progress_percent' => 'progress',
        ];
    }

    private function task(): ?Task
    {
        $bound = $this->route('task');

        return $bound instanceof Task ? $bound : null;
    }
}
