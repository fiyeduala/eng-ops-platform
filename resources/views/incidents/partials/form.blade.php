{{--
    Reporting or amending an incident.

    THERE IS NO STATUS FIELD AND NO "REPORTABLE" TICKBOX ON THIS FORM. Both are decided elsewhere,
    by people holding different permissions — status through the workflow engine, reportability
    behind `hse_incident.investigate`. Putting either here would let the person who filed the report
    also decide how serious it is recorded as being (§84).

    NO MEDICAL FIELDS EITHER. Who was hurt and how is recorded on the person rows on the show page,
    where `hse_incident.view_confidential` gates every one of them. This form is reachable by
    anybody who can edit the incident, which is a much wider set (§68).

    SEVERITY AND POTENTIAL SEVERITY ARE BOTH ASKED FOR, and the second one is the one that matters.
    A dropped scaffold board that hit nobody is "negligible" actual and "catastrophic" potential,
    and a register that only records the first will report a company getting safer while it is
    getting luckier.

    Expects: $incident, $projects, $units, $people, $categories, $severities, $confidentialities
--}}
<div class="space-y-6">

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">What happened</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Title" name="title" required
                     help="One line, factual. This is what appears on the register and in the notification.">
                <x-input name="title" :value="old('title', $incident->title)" required maxlength="300"
                         placeholder="e.g. Scaffold board fell from level 3 gantry"/>
            </x-field>

            <x-field label="Description" name="description"
                     help="What happened, in sequence, in the words of whoever saw it. Say what you know and leave out what you are guessing — the investigation is where cause gets decided.">
                <x-textarea name="description" rows="7">{{ old('description', $incident->description) }}</x-textarea>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Category" name="category">
                    <x-select name="category" :options="$categories"
                              :selected="old('category', $incident->category)"
                              placeholder="Not categorised"/>
                </x-field>

                <x-field label="Location" name="location"
                         help="Where on site. Specific enough that somebody could walk to it.">
                    <x-input name="location" :value="old('location', $incident->location)" maxlength="200"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Actual severity" name="severity" help="What actually happened.">
                    <x-select name="severity" :options="$severities"
                              :selected="old('severity', $incident->severity)"
                              placeholder="Not assessed"/>
                </x-field>

                <x-field label="Potential severity" name="potential_severity"
                         help="What could reasonably have happened. This is the figure a near miss is worth recording for.">
                    <x-select name="potential_severity" :options="$severities"
                              :selected="old('potential_severity', $incident->potential_severity)"
                              placeholder="Not assessed"/>
                </x-field>
            </div>

            <x-field label="Immediate actions taken" name="immediate_actions"
                     help="What was done there and then — area cordoned, plant isolated, first aid given. Not the corrective actions, which are raised separately and tracked to completion.">
                <x-textarea name="immediate_actions" rows="4">{{ old('immediate_actions', $incident->immediate_actions) }}</x-textarea>
            </x-field>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">When</h3>
            <span class="text-xs text-[var(--text-muted)]">The gap between these two is reported on</span>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Occurred at" name="occurred_at">
                    <x-input type="datetime-local" name="occurred_at"
                             :value="old('occurred_at', $incident->occurred_at?->format('Y-m-d\TH:i'))"/>
                </x-field>

                <x-field label="Reported at" name="reported_at"
                         help="Leave blank and this is the moment the report is filed, which is the honest answer.">
                    <x-input type="datetime-local" name="reported_at"
                             :value="old('reported_at', $incident->reported_at?->format('Y-m-d\TH:i'))"/>
                </x-field>
            </div>

            <x-field label="Reported by" name="reported_by"
                     help="The person who raised it, who is frequently not the person typing.">
                <x-select name="reported_by" :options="$people"
                          :selected="old('reported_by', $incident->reported_by)"
                          placeholder="Not recorded"/>
            </x-field>
        </div>
    </div>
</div>

<div class="mt-6 space-y-6">

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Lost time</h3>
        </div>

        <div class="space-y-5 p-5" x-data="{ lost: {{ old('is_lost_time', $incident->is_lost_time) ? 'true' : 'false' }} }">
            <p class="text-sm text-[var(--text-muted)]">
                A summary figure for the incident as a whole. Days lost by each individual are
                recorded against that person, where the medical permission applies. What counts as
                lost time is your organization's own definition — this platform does not assume one.
            </p>

            <label class="flex items-start gap-2.5">
                <input type="hidden" name="is_lost_time" value="0">
                <input type="checkbox" name="is_lost_time" value="1" x-model="lost"
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm text-[var(--text-body)]">This incident resulted in lost time</span>
            </label>

            <div x-show="lost" x-cloak>
                <x-field label="Days lost" name="lost_time_days">
                    <x-input type="number" name="lost_time_days" min="0" max="3650"
                             :value="old('lost_time_days', $incident->lost_time_days)"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Where it sits</h3>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $incident->project_id)"
                              placeholder="Not against a project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $incident->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Department" name="org_unit_id"
                         help="The unit answerable for it. This is what decides who else in the company can reach the record.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $incident->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality"
                         help="Incidents default to restricted because they name people.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $incident->confidentiality?->value)"/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="hse_incident" :record="$incident" class="card mt-5"/>
