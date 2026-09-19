@if ($blockers !== [])
    <div class="mb-4 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
        <p class="font-medium">Still in their name</p>
        <ul class="mt-2 list-disc pl-5">
            @foreach ($blockers as $blocker)<li>{{ $blocker }}</li>@endforeach
        </ul>
        <p class="mt-2">These come from the asset and petty cash registers, and they are checked again before the exit can be cleared.</p>
    </div>
@endif
<div class="grid gap-4 md:grid-cols-2">
    @if ($exit->exists)
        {{-- Posted so the request's rules still have something to validate, and thrown away by the
             controller: whose exit it is cannot be changed by editing the form. --}}
        <input type="hidden" name="employee_id" value="{{ $exit->employee_id }}">
        <x-field label="Person" name="employee_id_display" class="md:col-span-2" help="Who is leaving cannot be changed once the exit is open.">
            <x-input name="employee_id_display" :value="$exit->employee?->fullName()" disabled/>
        </x-field>
    @else
        <x-field label="Person" name="employee_id" class="md:col-span-2" required>
            <x-select name="employee_id" :options="$employees" :selected="old('employee_id', $exit->employee_id)" placeholder="Choose"/>
        </x-field>
    @endif
    {{-- The reasons are the company's own list, not a fixed one in code: what counts as redundancy
         is a matter of local law and of contract (§47). --}}
    <x-field label="Why they are leaving" name="reason" required>
        <x-select name="reason" :options="$reasons" :selected="old('reason', $exit->reason)" placeholder="Choose"/>
    </x-field>
    <x-field label="Notice given on" name="notified_on">
        <x-input type="date" name="notified_on" :value="old('notified_on', $exit->notified_on?->format('Y-m-d'))"/>
    </x-field>
    <x-field label="Last working day" name="last_working_day" required help="The exit cannot be completed before this date has arrived.">
        <x-input type="date" name="last_working_day" :value="old('last_working_day', $exit->last_working_day?->format('Y-m-d'))"/>
    </x-field>
    <x-field label="Eligible for rehire" name="eligible_for_rehire">
        <x-select name="eligible_for_rehire" :options="['1' => 'Yes', '0' => 'No']" :selected="old('eligible_for_rehire', $exit->eligible_for_rehire === null ? null : (string) (int) $exit->eligible_for_rehire)" placeholder="Not decided"/>
    </x-field>
</div>
<x-field label="Notes" name="notes" class="mt-4">
    <x-textarea name="notes" rows="3">{{ old('notes', $exit->notes) }}</x-textarea>
</x-field>
@if ($canViewInterview)
    <x-field label="Exit interview" name="interview_notes" class="mt-4" help="Read only by people holding the exit interview permission — not by the leaver's line manager.">
        <x-textarea name="interview_notes" rows="4">{{ old('interview_notes', $exit->interview_notes) }}</x-textarea>
    </x-field>
@endif


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="employee_exit" :record="$exit" class="card mt-5"/>
