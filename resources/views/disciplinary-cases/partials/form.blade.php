{{--
    Opening a case records an ALLEGATION, not a finding. Nothing on this form says what happened —
    that is what the investigation and the hearing are for, and the outcome is a separate act with
    its own permission. A form that let somebody type the allegation and the sanction in one sitting
    would be the platform helping to skip the process.
--}}
<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Person" name="employee_id" required help="Who the case concerns. Only people you are entitled to see are listed.">
        <x-select name="employee_id" :options="$employees" :selected="old('employee_id', $case->employee_id)" placeholder="Choose a person"/>
    </x-field>

    <x-field label="Category" name="category" required help="Your company's list, from the handbook — not the platform's.">
        <x-select name="category" :options="$categories" :selected="old('category', $case->category)" placeholder="Choose a category"/>
    </x-field>

    <x-field label="Severity" name="severity" required
             help="How serious the allegation is, not how serious the outcome turns out to be.">
        <x-select name="severity" :options="$severities" :selected="old('severity', $case->severity)"/>
    </x-field>

    <x-field label="Incident date" name="incident_on" help="When it happened, if it was a single event.">
        <x-input type="date" name="incident_on" :value="old('incident_on', $case->incident_on?->toDateString())"/>
    </x-field>

    <x-field label="Reported on" name="reported_on" help="When it was brought to the company's attention.">
        <x-input type="date" name="reported_on" :value="old('reported_on', $case->reported_on?->toDateString() ?? $case->reported_on)"/>
    </x-field>
</div>

<div class="mt-6">
    <x-field label="The allegation" name="summary" required
             help="What is said to have happened, in plain words. Written as an allegation — the person has not answered it yet.">
        <x-textarea name="summary" rows="6" required>{{ old('summary', $case->summary) }}</x-textarea>
    </x-field>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="disciplinary_case" :record="$case" class="card mt-5"/>
