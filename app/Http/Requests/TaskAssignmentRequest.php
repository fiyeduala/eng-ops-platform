<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Put a task on somebody, or take it off them.
 *
 * One field, because assignment is one decision. Validation establishes only that the id names a
 * usable account in THIS tenant; whether the actor may commit that particular person is a policy
 * question — TaskPolicy::assign() / reassign() — asked in the controller with both the task and
 * the candidate resolved.
 *
 * That split is deliberate and it is the same one ProjectMemberRequest makes. Answering an
 * authorization question in a class whose job is shape means two places decide who may be
 * assigned work, and they drift.
 *
 * A blank value is legal and means "unassign", which revokes rather than grants.
 */
class TaskAssignmentRequest extends TenantRequest
{
    public function rules(): array
    {
        return [
            'assignee_id' => ['nullable', 'integer', $this->existsInTenant('users', 'id', true)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['assignee_id']);
    }

    public function attributes(): array
    {
        return ['assignee_id' => 'assignee'];
    }
}
