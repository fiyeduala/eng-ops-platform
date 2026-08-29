<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Employee;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or edit a personnel record (§4).
 *
 * A personnel record is not one uniform blob of sensitivity, and EmployeePolicy already splits
 * reading it three ways — org-chart data, contact details, confidential identity data. Writing
 * has to respect the same split, which is what attributesFor() below is for: a viewer who cannot
 * SEE someone's national ID must not be able to overwrite it either, and a form that simply
 * omitted those inputs would still accept them if they were re-added by hand (§84).
 */
class EmployeeRequest extends TenantRequest
{
    /**
     * Fields only visible with employee.view_contact.
     *
     * @var array<int,string>
     */
    public const CONTACT_FIELDS = [
        'personal_email', 'personal_phone', 'address',
        'emergency_contact_name', 'emergency_contact_phone',
    ];

    /**
     * Fields only visible with employee.view_confidential.
     *
     * @var array<int,string>
     */
    public const CONFIDENTIAL_FIELDS = [
        'date_of_birth', 'national_id', 'gender', 'nationality',
    ];

    public function rules(): array
    {
        $employee = $this->employee();

        return [
            // --- identity -----------------------------------------------------------------
            'employee_no' => [
                'required', 'string', 'max:64',
                $this->uniqueInTenant('employees', 'employee_no', $employee?->id),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:150'],

            // The login account, if this person has one. Nullable because plenty of people on a
            // site have a personnel record and no system access at all.
            'user_id' => [
                'nullable', 'integer',
                $this->existsInTenant('users'),
                Rule::unique('employees', 'user_id')
                    ->where(fn ($q) => $q->where('organization_id', $this->organizationId())->whereNull('deleted_at'))
                    ->ignore($employee?->id),
            ],

            // --- placement ----------------------------------------------------------------
            'org_unit_id' => ['nullable', 'integer', $this->existsInTenant('org_units')],
            'position_id' => ['nullable', 'integer', $this->existsInTenant('positions')],
            'supervisor_id' => ['nullable', 'integer', $this->existsInTenant('employees')],
            'location_id' => ['nullable', 'integer', $this->existsInTenant('locations')],

            // --- work contact -------------------------------------------------------------
            'work_email' => ['nullable', 'email', 'max:150'],
            'work_phone' => ['nullable', 'string', 'max:40'],

            // --- personal contact ---------------------------------------------------------
            'personal_email' => ['nullable', 'email', 'max:150'],
            'personal_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],

            // --- confidential -------------------------------------------------------------
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', 'string', 'max:20'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'national_id' => ['nullable', 'string', 'max:64'],

            // --- employment ---------------------------------------------------------------
            'employment_type' => ['required', Rule::in(self::employmentTypes())],
            'employment_status' => ['required', Rule::in(self::employmentStatuses())],
            'hired_on' => ['nullable', 'date'],
            'probation_ends_on' => ['nullable', 'date', 'after_or_equal:hired_on'],
            'contract_ends_on' => ['nullable', 'date', 'after_or_equal:hired_on'],
            'exited_on' => ['nullable', 'date', 'after_or_equal:hired_on'],
            'exit_reason' => ['nullable', 'string', 'max:255'],

            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $employee = $this->employee();
            $supervisorId = $this->input('supervisor_id');

            if ($employee === null || $supervisorId === null) {
                return;
            }

            if ((int) $supervisorId === (int) $employee->id) {
                $validator->errors()->add('supervisor_id', 'A person cannot report to themselves.');

                return;
            }

            // A reporting line that loops would make every upward walk — escalation, approval
            // routing, the org chart — either wrong or infinite. The walk is depth-capped for the
            // same reason.
            if ($this->wouldCycle((int) $supervisorId, (int) $employee->id)) {
                $validator->errors()->add(
                    'supervisor_id',
                    'That person already reports to this employee, directly or indirectly.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->nullifyBlanks([
            'user_id', 'org_unit_id', 'position_id', 'supervisor_id', 'location_id',
            'middle_name', 'display_name', 'work_email', 'work_phone',
            ...self::CONTACT_FIELDS, ...self::CONFIDENTIAL_FIELDS,
            'hired_on', 'probation_ends_on', 'contract_ends_on', 'exited_on', 'exit_reason',
        ]);

        $this->normaliseBooleans(['is_active']);
    }

    /**
     * The validated payload, reduced to what this actor is actually allowed to write.
     *
     * Dropping rather than rejecting is deliberate. A team lead editing a colleague's work phone
     * should not be handed a validation error about a field their form never showed them; the
     * field is simply not theirs to change, so it is left as it was.
     *
     * @return array<string,mixed>
     */
    public function attributesFor(Employee $record): array
    {
        $data = $this->validated();
        $user = $this->user();

        // On create there is no row yet, so the record-level gates have nothing to test against
        // and would refuse everything. The class-level permission is the right question at that
        // point: "may this person record contact details at all". Once the row exists, the full
        // policy runs, because reach over THIS employee is what actually matters.
        $mayWriteContact = $record->exists
            ? $user->can('viewContact', $record)
            : $user->hasPermission('employee.view_contact');

        $mayWriteConfidential = $record->exists
            ? $user->can('viewConfidential', $record)
            : $user->hasPermission('employee.view_confidential');

        if (! $mayWriteContact) {
            $data = array_diff_key($data, array_flip(self::CONTACT_FIELDS));
        }

        if (! $mayWriteConfidential) {
            $data = array_diff_key($data, array_flip(self::CONFIDENTIAL_FIELDS));
        }

        return $data;
    }

    /** @return array<int,string> */
    public static function employmentTypes(): array
    {
        return ['full_time', 'part_time', 'contract', 'consultant', 'intern'];
    }

    /** @return array<int,string> */
    public static function employmentStatuses(): array
    {
        return ['active', 'probation', 'suspended', 'on_leave', 'exited'];
    }

    public function attributes(): array
    {
        return [
            'employee_no' => 'employee number',
            'org_unit_id' => 'organization unit',
            'position_id' => 'position',
            'supervisor_id' => 'supervisor',
            'location_id' => 'location',
            'user_id' => 'login account',
            'national_id' => 'national ID',
        ];
    }

    private function employee(): ?Employee
    {
        $bound = $this->route('employee');

        return $bound instanceof Employee ? $bound : null;
    }

    /**
     * Does making $supervisorId the supervisor of $employeeId create a loop?
     */
    private function wouldCycle(int $supervisorId, int $employeeId, int $maxDepth = 20): bool
    {
        $current = $supervisorId;
        $seen = [];

        for ($i = 0; $i < $maxDepth && $current !== 0; $i++) {
            if ($current === $employeeId) {
                return true;
            }

            if (isset($seen[$current])) {
                return true;   // the existing data is already looped; refuse to add to it
            }

            $seen[$current] = true;

            $current = (int) (Employee::whereKey($current)->value('supervisor_id') ?? 0);
        }

        return false;
    }
}
