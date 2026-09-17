<div class="grid gap-4 md:grid-cols-2">
    @if ($canChooseEmployee && ! $leave->exists)
        <x-field label="Person" name="employee_id" class="md:col-span-2" help="Booking for somebody else. Leave blank to book your own.">
            <x-select name="employee_id" :options="$employees" :selected="old('employee_id', $leave->employee_id)" placeholder="Me"/>
        </x-field>
    @endif
    <x-field label="Type of leave" name="leave_type_id" required class="md:col-span-2">
        <x-select name="leave_type_id" :options="$types" :selected="old('leave_type_id', $leave->leave_type_id)" placeholder="Choose a type"/>
    </x-field>
    <x-field label="First day" name="start_date" required><x-input type="date" name="start_date" :value="old('start_date', $leave->start_date?->format('Y-m-d'))"/></x-field>
    <x-field label="Last day" name="end_date" required><x-input type="date" name="end_date" :value="old('end_date', $leave->end_date?->format('Y-m-d'))"/></x-field>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="start_half_day" value="0"><input type="checkbox" name="start_half_day" value="1" @checked(old('start_half_day', $leave->start_half_day))> Afternoon only on the first day</label>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="end_half_day" value="0"><input type="checkbox" name="end_half_day" value="1" @checked(old('end_half_day', $leave->end_half_day))> Morning only on the last day</label>
    <x-field label="Handing over to" name="handover_to"><x-select name="handover_to" :options="$colleagues" :selected="old('handover_to', $leave->handover_to)" placeholder="Nobody"/></x-field>
    <x-field label="Contact while away" name="contact_while_away"><x-input name="contact_while_away" :value="old('contact_while_away', $leave->contact_while_away)" maxlength="200" placeholder="Optional"/></x-field>
</div>
<x-field label="Reason" name="reason" class="mt-4" help="Seen by you, your approver and HR. It is never shown in notifications or on the team calendar.">
    <x-textarea name="reason" rows="3">{{ old('reason', $leave->reason) }}</x-textarea>
</x-field>
<p class="mt-3 text-xs text-[var(--text-muted)]">Days are counted on the server from the company's working days and public holidays.</p>
