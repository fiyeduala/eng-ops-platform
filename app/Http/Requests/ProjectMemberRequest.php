<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProjectMember;
use Illuminate\Validation\Validator;

/**
 * Put someone on a project team, or change their assignment (§12, §14).
 *
 * This is the most security-sensitive form in Phase 2, because a member row is a grant: the
 * moment it exists, ActorProfile hands that account the whole project axis for this project.
 *
 * Validation therefore establishes only that the person EXISTS in this tenant. Whether the actor
 * may commit that particular person is a policy question — ProjectMemberPolicy::assign() — and it
 * is asked in the controller with both the project and the candidate in hand. Answering it here
 * would put an authorization decision in a class whose job is shape, and the two get out of step.
 *
 * `employee_id` is absent on purpose: it is derived from the account by ProjectTeamService, so a
 * form cannot post a member row that names one person and points at another's employment.
 */
class ProjectMemberRequest extends TenantRequest
{
    public function rules(): array
    {
        $member = $this->member();

        return [
            // On edit the person is fixed: moving a membership row to someone else is a removal
            // and an addition, and each asks the policy a different question.
            'user_id' => $member !== null
                ? ['nullable']
                : ['required', 'integer', $this->existsInTenant('users', 'id', true)],

            'project_role_id' => ['nullable', 'integer', $this->existsInTenant('project_roles', 'id', true)],
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'responsibilities' => ['nullable', 'string', 'max:1000'],
            'allocation_percent' => ['nullable', 'integer', 'between:0,100'],

            // The time bounds are the §12 mechanism: a consultant assigned for three months loses
            // project visibility the day after ends_on, with nobody remembering to revoke it.
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],

            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $member = $this->member();

            if ($member !== null || ! $this->filled('user_id')) {
                return;
            }

            $projectId = $this->route('project')?->getKey();

            if ($projectId === null) {
                return;
            }

            // The unique key on (project_id, user_id) would catch this as a database error; caught
            // here it reads as "they are already on the team" instead of a 500.
            $already = ProjectMember::query()
                ->where('project_id', $projectId)
                ->where('user_id', (int) $this->input('user_id'))
                ->exists();

            if ($already) {
                $validator->errors()->add('user_id', 'That person is already on this project team.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'project_role_id', 'org_unit_id', 'responsibilities',
            'allocation_percent', 'starts_on', 'ends_on',
        ]);

        // Only on the editor, which actually renders an Active checkbox. There, an absent key means
        // "I unticked it" and must become false. On the add form there is no such control, and
        // normalising the absent key would write is_active = false — a membership row that exists,
        // appears on the roster, and grants nothing. "Add to team" would silently do nothing, which
        // is the worst way for a grant to fail.
        if ($this->member() !== null) {
            $this->normaliseBooleans(['is_active']);
        }
    }

    public function attributes(): array
    {
        return [
            'user_id' => 'team member',
            'project_role_id' => 'project role',
            'org_unit_id' => 'department',
            'allocation_percent' => 'allocation',
            'starts_on' => 'start date',
            'ends_on' => 'end date',
        ];
    }

    private function member(): ?ProjectMember
    {
        $bound = $this->route('member');

        return $bound instanceof ProjectMember ? $bound : null;
    }
}
