<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * One line of a task's checklist.
 *
 * `task_id` and `organization_id` are absent by design: both are taken from the bound parent task
 * in the controller, so there is no field on which a crafted POST could file a checklist line
 * against another task — or another company's task — even if this class were one day relaxed (§84).
 *
 * `is_done` is absent too. Ticking a box has its own route, because it stamps who ticked it and
 * when, and an editor that could set the flag without the stamp would produce a completed
 * inspection line with nobody's name on it.
 */
class TaskChecklistItemRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:300'],
            'sort' => ['nullable', 'integer', 'between:0,65000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['sort']);
    }

    public function attributes(): array
    {
        return ['title' => 'item', 'sort' => 'order'];
    }
}
