<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Declare that a task waits for another one.
 *
 * `existsInTenant` pins the blocking task to the actor's own organization, which stops the obvious
 * cross-tenant edge. It does NOT establish that the actor may see that task, and it cannot: that
 * is Gates 3 and 5, and they need the model. The controller therefore re-resolves the blocking
 * task and asks TaskPolicy about it before calling the service — otherwise the dependency picker
 * would answer "does task 412 exist in this company?" for any id somebody cared to type, and the
 * task's number and title would then render on the page (§61, §84).
 *
 * Cycles are the service's to refuse. A form request can see one edge; a cycle is a property of
 * the whole graph.
 */
class TaskDependencyRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'depends_on_task_id' => ['required', 'integer', $this->existsInTenant('tasks')],
            'type' => ['nullable', Rule::in(self::types())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['type' => $this->input('type') ?: 'finish_to_start']);
    }

    /**
     * The four PERT relations. Only finish_to_start is offered by the V1 UI; the rest are here so
     * a later scheduling module is a configuration change rather than a migration.
     *
     * @return array<int,string>
     */
    public static function types(): array
    {
        return ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'];
    }

    public function attributes(): array
    {
        return ['depends_on_task_id' => 'blocking task', 'type' => 'dependency type'];
    }
}
