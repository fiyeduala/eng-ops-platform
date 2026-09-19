{{--
    Shared by create and edit.

    Three fields a project has that are NOT on this form, and their absence is deliberate:

      - the reference number, issued by the numbering engine at save (§64)
      - the status, which moves through the configured whitelist on the project page (§63)
      - the organization, which comes from the session and never from a form (§74)

    The commercial block renders only for someone who may see it. That is a courtesy, not the
    control: ProjectRequest::attributesFor() drops those keys from the payload for the same actor,
    so re-adding the inputs by hand changes nothing (§84).

    Expects: $project, $types, $units, $locations, $people, $confidentialities, $priorities,
             $healths, $canSeeFinancials, $nextNumber
--}}

{{-- ---------------------------------------------------------------- identity --}}
<div class="card-header">
    <h3 class="card-title">The project</h3>
    @if ($nextNumber)
        <span class="font-mono text-xs text-[var(--text-muted)]">Next number: {{ $nextNumber }}</span>
    @endif
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Name" name="name" required class="sm:col-span-2">
        <x-input name="name" :value="$project->name" required autofocus/>
    </x-field>

    <x-field label="Short code" name="code"
             help="Your own shorthand, e.g. “ALPHA”. Optional, and separate from the reference number the system issues.">
        <x-input name="code" :value="$project->code"/>
    </x-field>

    <x-field label="Type" name="project_type_id">
        <x-select name="project_type_id" :options="$types" :selected="$project->project_type_id" placeholder="Not categorised"/>
    </x-field>

    <x-field label="Description" name="description" class="sm:col-span-2">
        <x-textarea name="description" :value="$project->description" rows="4"/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- client --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Client</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Client" name="client_name"
             help="Recorded as free text for now. A client register arrives with the commercial modules.">
        <x-input name="client_name" :value="$project->client_name"/>
    </x-field>

    <x-field label="Contract reference" name="contract_reference">
        <x-input name="contract_reference" :value="$project->contract_reference"/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- accountability --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Accountability</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Owning unit" name="org_unit_id"
             help="Which part of the company owns this job. People who can see that unit can see this project.">
        <x-select name="org_unit_id" :options="$units" :selected="$project->org_unit_id" placeholder="Not assigned"/>
    </x-field>

    <x-field label="Location" name="location_id">
        <x-select name="location_id" :options="$locations" :selected="$project->location_id" placeholder="No site recorded"/>
    </x-field>

    <x-field label="Project manager" name="manager_id"
             help="Named here, and placed on the team automatically — running a project you cannot open is not a thing.">
        @if ($people === [])
            <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                You cannot see login accounts, so you cannot name a manager.
            </p>
        @else
            <x-select name="manager_id" :options="$people" :selected="$project->manager_id" placeholder="Not appointed"/>
        @endif
    </x-field>

    <x-field label="Sponsor" name="sponsor_id"
             help="Who the manager escalates to.">
        @if ($people === [])
            <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                Not available to you.
            </p>
        @else
            <x-select name="sponsor_id" :options="$people" :selected="$project->sponsor_id" placeholder="None"/>
        @endif
    </x-field>

    <x-field label="Sensitivity" name="confidentiality" required class="sm:col-span-2"
             help="Above “Internal”, being on the team is not enough on its own — the project has to be reachable through the owning unit or an explicit grant.">
        <x-select name="confidentiality" :options="$confidentialities"
                  :selected="$project->confidentiality?->value" required/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- schedule --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Schedule</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Planned start" name="planned_start">
        <x-input name="planned_start" type="date" :value="$project->planned_start?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Planned end" name="planned_end">
        <x-input name="planned_end" type="date" :value="$project->planned_end?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Actual start" name="actual_start">
        <x-input name="actual_start" type="date" :value="$project->actual_start?->format('Y-m-d')"/>
    </x-field>

    <x-field label="Actual end" name="actual_end"
             help="Filled in for you when the project reaches a closing status.">
        <x-input name="actual_end" type="date" :value="$project->actual_end?->format('Y-m-d')"/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- progress --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Progress</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Priority" name="priority" required>
        <x-select name="priority" :options="$priorities" :selected="$project->priority" required/>
    </x-field>

    <x-field label="Health" name="health"
             help="Deliberately separate from status: a project can be “In progress” and “At risk” at the same time.">
        <x-select name="health" :options="$healths" :selected="$project->health" placeholder="Not assessed"/>
    </x-field>

    <x-field label="Progress" name="progress_percent" help="Percent complete, 0–100.">
        <x-input name="progress_percent" type="number" min="0" max="100" :value="$project->progress_percent"/>
    </x-field>

    <label class="flex items-center gap-2 self-end pb-2 text-sm text-[var(--text-body)]">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $project->is_active ?? true))
               class="h-4 w-4 rounded border-[var(--surface-border)]">
        Active
    </label>
</div>

{{-- ---------------------------------------------------------------- commercial --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Commercial</h3>
    @unless ($canSeeFinancials)
        <x-badge tone="neutral"><x-icon name="lock" class="h-3 w-3"/> Restricted</x-badge>
    @endunless
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    @if ($canSeeFinancials)
        <x-field label="Contract value" name="contract_value">
            <x-input name="contract_value" type="number" step="0.01" min="0" :value="$project->contract_value"/>
        </x-field>

        <x-field label="Currency" name="currency" help="Three-letter code, e.g. USD, NGN, GBP.">
            <x-input name="currency" :value="$project->currency" maxlength="8"/>
        </x-field>
    @else
        <p class="text-sm text-[var(--text-muted)] sm:col-span-2">
            Contract value and currency are not shown and cannot be changed here. Editing them needs the
            <span class="font-medium text-[var(--text-strong)]">project.view_financials</span> permission.
        </p>
    @endif
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="project" :record="$project" class="card mt-5"/>
