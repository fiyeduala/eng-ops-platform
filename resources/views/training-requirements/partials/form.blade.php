{{--
    The scope picker decides which target matters, and the form shows only that one.

    Alpine hides the irrelevant field rather than disabling it, and the SERVICE clears whichever the
    scope did not select — so a stale position id left in a hidden input by a browser, a back button
    or a crafted post cannot survive as a second, contradictory target (§84). The hiding is for the
    person filling it in; the clearing is what makes it true.
--}}
<div x-data="{ scope: '{{ old('scope', $requirement->scope ?? \App\Models\TrainingRequirement::SCOPE_POSITION) }}' }" class="grid gap-4 md:grid-cols-2">
    <x-field label="Course" name="training_course_id" class="md:col-span-2" required
             help="The certificate people are required to hold. How long it lasts is a property of the course itself.">
        <x-select name="training_course_id" :options="$courses" :selected="old('training_course_id', $requirement->training_course_id)" placeholder="Choose a course"/>
    </x-field>

    <x-field label="Who has to hold it" name="scope" required>
        <select name="scope" id="scope" x-model="scope" class="input">
            @foreach ($scopes as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </x-field>

    <div x-show="scope === '{{ \App\Models\TrainingRequirement::SCOPE_POSITION }}'" x-cloak>
        <x-field label="Position" name="position_id" required>
            <x-select name="position_id" :options="$positions" :selected="old('position_id', $requirement->position_id)" placeholder="Choose a position"/>
        </x-field>
        @if ($positions === [])
            <p class="mt-1 text-xs text-[var(--text-muted)]">You cannot see any positions, so you cannot target one. Ask for the position permission.</p>
        @endif
    </div>

    <div x-show="scope === '{{ \App\Models\TrainingRequirement::SCOPE_ORG_UNIT }}'" x-cloak>
        <x-field label="Department" name="org_unit_id" required help="Applies to everybody in the department, not the ones beneath it.">
            <x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $requirement->org_unit_id)" placeholder="Choose a department"/>
        </x-field>
    </div>

    <x-field label="Mandatory" name="is_mandatory"
             help="Mandatory requirements count as a gap on the matrix. Recommended ones are shown but not counted.">
        <x-select name="is_mandatory" :options="['1' => 'Mandatory', '0' => 'Recommended']"
                  :selected="old('is_mandatory', (string) (int) ($requirement->is_mandatory ?? true))"/>
    </x-field>

    <x-field label="Grace period (days)" name="grace_days"
             help="How long somebody may keep working on a lapsed certificate while the refresher is booked. Zero means it lapses on the day.">
        <x-input type="number" name="grace_days" min="0" max="365" :value="old('grace_days', $requirement->grace_days ?? 0)"/>
    </x-field>

    <x-field label="Active" name="is_active" help="Retiring a requirement takes it off the matrix and leaves every certificate on file untouched.">
        <x-select name="is_active" :options="['1' => 'Active', '0' => 'Retired']"
                  :selected="old('is_active', (string) (int) ($requirement->is_active ?? true))"/>
    </x-field>

    <x-field label="Notes" name="notes" class="md:col-span-2">
        <x-textarea name="notes" rows="2" placeholder="Why this is required — the regulation, the client requirement, the internal standard.">{{ old('notes', $requirement->notes) }}</x-textarea>
    </x-field>
</div>
