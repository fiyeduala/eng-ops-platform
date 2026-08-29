{{--
    Shared by create and edit.

    The contact and identity blocks are rendered only when the actor may write them. That is a
    courtesy, not the control: EmployeeRequest::attributesFor() drops those keys from the payload
    for the same actor, so re-adding the inputs by hand changes nothing (§84).

    Expects: $employee, $units, $positions, $locations, $supervisors, $accounts,
             $employmentTypes, $employmentStatuses, $canSeeContact, $canSeeConfidential
--}}

{{-- ---------------------------------------------------------------- identity --}}
<div class="card-header">
    <h3 class="card-title">Identity</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Employee number" name="employee_no" required
             help="Unique within this company.">
        <x-input name="employee_no" :value="$employee->employee_no" required/>
    </x-field>

    <x-field label="Display name" name="display_name"
             help="Overrides the assembled name wherever the person appears. Leave empty for “First Last”.">
        <x-input name="display_name" :value="$employee->display_name"/>
    </x-field>

    <x-field label="First name" name="first_name" required>
        <x-input name="first_name" :value="$employee->first_name" required/>
    </x-field>

    <x-field label="Middle name" name="middle_name">
        <x-input name="middle_name" :value="$employee->middle_name"/>
    </x-field>

    <x-field label="Last name" name="last_name" required>
        <x-input name="last_name" :value="$employee->last_name" required/>
    </x-field>

    <x-field label="Login account" name="user_id"
             help="Links this person to an account that can sign in. Optional — plenty of people on a site never log in.">
        @if ($accounts === [])
            <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                You cannot see login accounts, so you cannot link one.
            </p>
        @else
            <x-select name="user_id" :options="$accounts" :selected="$employee->user_id" placeholder="No account"/>
        @endif
    </x-field>
</div>

{{-- ---------------------------------------------------------------- placement --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Placement</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Unit" name="org_unit_id" required
             help="Where this person sits. This is what decides who can see their records.">
        <x-select name="org_unit_id" :options="$units" :selected="$employee->org_unit_id" placeholder="Choose a unit" required/>
    </x-field>

    <x-field label="Position" name="position_id">
        @if ($positions === [])
            <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                You cannot see position records, so you cannot set one.
            </p>
        @else
            <x-select name="position_id" :options="$positions" :selected="$employee->position_id" placeholder="No position"/>
        @endif
    </x-field>

    <x-field label="Reports to" name="supervisor_id"
             help="The reporting line. A supervisor can see their direct reports’ records.">
        <x-select name="supervisor_id" :options="$supervisors" :selected="$employee->supervisor_id" placeholder="Nobody"/>
    </x-field>

    <x-field label="Base location" name="location_id">
        <x-select name="location_id" :options="$locations" :selected="$employee->location_id" placeholder="No fixed base"/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- employment --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Employment</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Employment type" name="employment_type" required>
        <x-select name="employment_type" :options="$employmentTypes" :selected="$employee->employment_type" required/>
    </x-field>

    <x-field label="Status" name="employment_status" required>
        <x-select name="employment_status" :options="$employmentStatuses" :selected="$employee->employment_status" required/>
    </x-field>

    <x-field label="Hired on" name="hired_on">
        <x-input name="hired_on" type="date" :value="$employee->hired_on?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Probation ends" name="probation_ends_on">
        <x-input name="probation_ends_on" type="date" :value="$employee->probation_ends_on?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Contract ends" name="contract_ends_on"
             help="Leave empty for permanent employment. Expiry reminders will be driven from this.">
        <x-input name="contract_ends_on" type="date" :value="$employee->contract_ends_on?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Left on" name="exited_on">
        <x-input name="exited_on" type="date" :value="$employee->exited_on?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Reason for leaving" name="exit_reason" class="sm:col-span-2">
        <x-input name="exit_reason" :value="$employee->exit_reason"/>
    </x-field>

    <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $employee->is_active ?? true))
               class="h-4 w-4 rounded border-[var(--surface-border)]">
        Active record
    </label>
</div>

{{-- ---------------------------------------------------------------- contact --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Contact</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Work email" name="work_email">
        <x-input name="work_email" type="email" :value="$employee->work_email"/>
    </x-field>

    <x-field label="Work phone" name="work_phone">
        <x-input name="work_phone" type="tel" :value="$employee->work_phone"/>
    </x-field>

    @if ($canSeeContact)
        <x-field label="Personal email" name="personal_email">
            <x-input name="personal_email" type="email" :value="$employee->personal_email"/>
        </x-field>

        <x-field label="Personal phone" name="personal_phone">
            <x-input name="personal_phone" type="tel" :value="$employee->personal_phone"/>
        </x-field>

        <x-field label="Home address" name="address" class="sm:col-span-2">
            <x-textarea name="address" :value="$employee->address" rows="3"/>
        </x-field>

        <x-field label="Emergency contact" name="emergency_contact_name">
            <x-input name="emergency_contact_name" :value="$employee->emergency_contact_name"/>
        </x-field>

        <x-field label="Emergency contact phone" name="emergency_contact_phone">
            <x-input name="emergency_contact_phone" type="tel" :value="$employee->emergency_contact_phone"/>
        </x-field>
    @else
        <p class="text-sm text-[var(--text-muted)] sm:col-span-2">
            Personal contact details are not shown and cannot be changed here. Editing them needs the
            <span class="font-medium text-[var(--text-strong)]">employee.view_contact</span> permission.
        </p>
    @endif
</div>

{{-- ---------------------------------------------------------------- confidential --}}
@if ($canSeeConfidential)
    <div class="card-header border-t border-[var(--surface-border)]">
        <h3 class="card-title">Personal identity</h3>
        <x-badge tone="warning"><x-icon name="shield" class="h-3 w-3"/> Confidential</x-badge>
    </div>

    <div class="grid gap-5 p-5 sm:grid-cols-2">
        <x-field label="Date of birth" name="date_of_birth">
            <x-input name="date_of_birth" type="date" :value="$employee->date_of_birth?->format('Y-m-d')"/>
        </x-field>

        <x-field label="National ID" name="national_id" help="Unique within this company where recorded.">
            <x-input name="national_id" :value="$employee->national_id"/>
        </x-field>

        <x-field label="Gender" name="gender" help="Recorded as the person states it.">
            <x-input name="gender" :value="$employee->gender"/>
        </x-field>

        <x-field label="Nationality" name="nationality">
            <x-input name="nationality" :value="$employee->nationality"/>
        </x-field>
    </div>
@endif
