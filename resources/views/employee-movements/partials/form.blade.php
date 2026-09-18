{{--
    A movement records what is CHANGING, not the person's whole record. Every "to" field is optional
    and blank means "stays as it is" — a transfer that moves somebody between departments without
    touching their job title says so by leaving the position empty, and applying it later will not
    quietly blank the title (see EmployeeMovementService).

    The "from" side is not on this form at all: it is read off the personnel record when the movement
    is created, so nobody can post a false starting point to make a change look smaller than it is.
--}}
<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Person" name="employee_id" required help="Who is moving. Only people you are entitled to see are listed.">
        <x-select name="employee_id" :options="$employees" :selected="old('employee_id', $movement->employee_id)" placeholder="Choose a person"/>
    </x-field>

    <x-field label="Kind" name="kind" required help="Your company's list. A secondment and a promotion are different facts about somebody.">
        <x-select name="kind" :options="$kinds" :selected="old('kind', $movement->kind)"/>
    </x-field>

    <x-field label="Effective on" name="effective_on" required
             help="The day it takes effect. The personnel record is rewritten on this date, not the day it is approved.">
        <x-input type="date" name="effective_on" :value="old('effective_on', $movement->effective_on?->toDateString() ?? $movement->effective_on)" required/>
    </x-field>

    <x-field label="Ends on" name="ends_on" help="For secondments and acting appointments. Leave empty for a permanent change.">
        <x-input type="date" name="ends_on" :value="old('ends_on', $movement->ends_on?->toDateString())"/>
    </x-field>
</div>

<h2 class="mt-8 mb-3 text-sm font-semibold text-[var(--text-strong)]">What changes</h2>
<p class="mb-3 text-xs text-[var(--text-muted)]">Fill in only what is different. Anything left blank stays exactly as it is today.</p>

<div class="grid gap-4 md:grid-cols-2">
    <x-field label="New position" name="to_position_id">
        @if ($positions === [])
            <p class="text-xs text-[var(--text-muted)]">You cannot see the position list, so you cannot change a job title here. The rest of the form still works.</p>
        @else
            <x-select name="to_position_id" :options="$positions" :selected="old('to_position_id', $movement->to_position_id)" placeholder="No change"/>
        @endif
    </x-field>

    <x-field label="New department" name="to_org_unit_id">
        <x-select name="to_org_unit_id" :options="$units" :selected="old('to_org_unit_id', $movement->to_org_unit_id)" placeholder="No change"/>
    </x-field>

    <x-field label="New location" name="to_location_id">
        <x-select name="to_location_id" :options="$locations" :selected="old('to_location_id', $movement->to_location_id)" placeholder="No change"/>
    </x-field>

    <x-field label="New supervisor" name="to_supervisor_id" help="The reporting line after the move.">
        <x-select name="to_supervisor_id" :options="$supervisors" :selected="old('to_supervisor_id', $movement->to_supervisor_id)" placeholder="No change"/>
    </x-field>
</div>

<div class="mt-6 grid gap-4">
    <x-field label="Reason" name="reason" required
             help="Why this is being proposed. The approver reads this and nothing else before deciding.">
        <x-textarea name="reason" rows="3" required>{{ old('reason', $movement->reason) }}</x-textarea>
    </x-field>

    <x-field label="Notes" name="notes" help="Anything HR needs alongside it — handover, the conversation already had, the paperwork still to come.">
        <x-textarea name="notes" rows="3">{{ old('notes', $movement->notes) }}</x-textarea>
    </x-field>
</div>
