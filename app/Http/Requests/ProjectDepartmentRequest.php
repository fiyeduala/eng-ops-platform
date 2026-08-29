<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProjectDepartment;
use Illuminate\Validation\Validator;

/**
 * Assign a department to a project — step 1 of the §13 cascade.
 *
 * Worth being precise about what this form does and does not do. It records that a department is
 * responsible for part of a project, which is what puts the project on that department head's
 * list. It grants NOBODY visibility of the project: the people in that department still see
 * nothing until the head staffs them individually, which is ProjectMemberRequest's job.
 *
 * If this row granted reach, "assign the department" would be a silent bulk grant over a whole
 * unit subtree — the opposite of the decision a department head is being asked to make.
 */
class ProjectDepartmentRequest extends TenantRequest
{
    public function rules(): array
    {
        $assignment = $this->assignment();

        return [
            // On edit the department is fixed. Re-pointing the row at a different unit is an
            // unassignment and an assignment, and each is its own decision.
            'org_unit_id' => $assignment !== null
                ? ['nullable']
                : ['required', 'integer', $this->existsInTenant('org_units')],

            'lead_employee_id' => ['nullable', 'integer', $this->existsInTenant('employees', 'id', true)],
            'responsibilities' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->assignment() !== null || ! $this->filled('org_unit_id')) {
                return;
            }

            $projectId = $this->route('project')?->getKey();

            if ($projectId === null) {
                return;
            }

            $already = ProjectDepartment::query()
                ->where('project_id', $projectId)
                ->where('org_unit_id', (int) $this->input('org_unit_id'))
                ->exists();

            if ($already) {
                $validator->errors()->add('org_unit_id', 'That department is already assigned to this project.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks(['lead_employee_id', 'responsibilities']);

        // Only on the editor, which renders an Active checkbox. The assign form has no such
        // control, and normalising its absent key would file every new assignment as inactive.
        if ($this->assignment() !== null) {
            $this->normaliseBooleans(['is_active']);
        }
    }

    public function attributes(): array
    {
        return [
            'org_unit_id' => 'department',
            'lead_employee_id' => 'department lead',
        ];
    }

    private function assignment(): ?ProjectDepartment
    {
        $bound = $this->route('department');

        return $bound instanceof ProjectDepartment ? $bound : null;
    }
}
