<div class="grid gap-4 md:grid-cols-2">
    @if ($canChooseEmployee)
        <x-field label="Person" name="employee_id" class="md:col-span-2" help="Asking on somebody else's behalf. Leave blank for your own.">
            <x-select name="employee_id" :options="$employees" :selected="old('employee_id', $overtime->employee_id)" placeholder="Me"/>
        </x-field>
    @endif
    <x-field label="Day" name="work_date" required><x-input type="date" name="work_date" :value="old('work_date', $overtime->work_date?->format('Y-m-d'))"/></x-field>
    <x-field label="Extra hours" name="hours" required><x-input type="text" inputmode="decimal" name="hours" :value="old('hours', $overtime->hours === null ? null : rtrim(rtrim((string) $overtime->hours, '0'), '.'))" maxlength="5" placeholder="e.g. 2.5"/></x-field>
    <x-field label="Project" name="project_id" class="md:col-span-2">
        <x-select name="project_id" :options="$projects" :selected="old('project_id', $overtime->project_id)" placeholder="None"/>
    </x-field>
</div>
<x-field label="Why is the overtime needed?" name="reason" required class="mt-4" help="Seen by you, your approver and HR — never in notifications.">
    <x-textarea name="reason" rows="3">{{ old('reason', $overtime->reason) }}</x-textarea>
</x-field>
