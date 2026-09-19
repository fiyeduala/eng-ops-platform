<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Job title" name="title" class="md:col-span-2" required>
        <x-input name="title" :value="old('title', $vacancy->title)" maxlength="200" placeholder="e.g. Senior Piping Engineer"/>
    </x-field>
    <x-field label="Position" name="position_id" help="The job title as it exists in the company's own list.">
        <x-select name="position_id" :options="$positions" :selected="old('position_id', $vacancy->position_id)" placeholder="Not tied to a position"/>
    </x-field>
    <x-field label="How many people?" name="openings" required help="The vacancy closes itself once this many have been hired.">
        <x-input type="number" name="openings" min="1" max="999" :value="old('openings', $vacancy->openings ?? 1)"/>
    </x-field>
    <x-field label="Department" name="org_unit_id">
        <x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $vacancy->org_unit_id)" placeholder="None"/>
    </x-field>
    <x-field label="Hiring manager" name="hiring_manager_id" help="They can see the applications and the CVs.">
        <x-select name="hiring_manager_id" :options="$people" :selected="old('hiring_manager_id', $vacancy->hiring_manager_id)" placeholder="None"/>
    </x-field>
    <x-field label="Location" name="location_id">
        <x-select name="location_id" :options="$locations" :selected="old('location_id', $vacancy->location_id)" placeholder="None"/>
    </x-field>
    <x-field label="Project" name="project_id">
        <x-select name="project_id" :options="$projects" :selected="old('project_id', $vacancy->project_id)" placeholder="None"/>
    </x-field>
    <x-field label="Employment type" name="employment_type">
        <x-select name="employment_type" :options="$employmentTypes" :selected="old('employment_type', $vacancy->employment_type)" placeholder="Not stated"/>
    </x-field>
    <x-field label="Target start date" name="target_start_date">
        <x-input type="date" name="target_start_date" :value="old('target_start_date', $vacancy->target_start_date?->format('Y-m-d'))"/>
    </x-field>
    <x-field label="Applications close on" name="closes_on" class="md:col-span-2">
        <x-input type="date" name="closes_on" :value="old('closes_on', $vacancy->closes_on?->format('Y-m-d'))"/>
    </x-field>
</div>
<x-field label="What the job is" name="summary" class="mt-4">
    <x-textarea name="summary" rows="4">{{ old('summary', $vacancy->summary) }}</x-textarea>
</x-field>
<x-field label="What the person needs" name="requirements" class="mt-4">
    <x-textarea name="requirements" rows="4">{{ old('requirements', $vacancy->requirements) }}</x-textarea>
</x-field>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="vacancy" :record="$vacancy" class="card mt-5"/>
