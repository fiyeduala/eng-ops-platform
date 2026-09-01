{{--
    The investigation itself.

    THE NARRATIVE FIELDS ARE ORDERED THE WAY THE THINKING GOES: what happened, then what caused it at
    the surface, then what caused THAT, then what else made it likely. Putting root cause first
    invites the guess before the sequence has been written down, and the guess is almost always
    "operator error".

    IMMEDIATE CAUSE AND ROOT CAUSE ARE SEPARATE BOXES ON PURPOSE. "The sling failed" is an immediate
    cause. "Slings are inspected by whoever is free rather than by a competent person" is a root
    cause. Only the second one, once fixed, stops it happening again — and only the second one is
    required before the investigation can be completed.

    THE INCIDENT CANNOT BE CHANGED HERE. `incident_id` is a protected attribute in the service and
    appears on no form in this application (§84).

    Expects: $investigation, $projects, $units, $people, $confidentialities, $methods
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The investigation</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required>
                    <x-input name="title" :value="old('title', $investigation->title)" required/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Method" name="method"
                             help="Whatever your company actually uses — the list is configurable.">
                        <x-select name="method" :options="$methods"
                                  :selected="old('method', $investigation->method)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Led by" name="lead_id">
                        <x-select name="lead_id" :options="$people"
                                  :selected="old('lead_id', $investigation->lead_id)"
                                  placeholder="Unassigned"/>
                    </x-field>

                    <x-field label="Started" name="started_on">
                        <x-input type="date" name="started_on"
                                 :value="old('started_on', $investigation->started_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Due by" name="due_on">
                        <x-input type="date" name="due_on"
                                 :value="old('due_on', $investigation->due_on?->format('Y-m-d'))"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What was found</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Sequence of events" name="sequence_of_events"
                         help="Plainly, in order, without blame. Times if you have them.">
                    <x-textarea name="sequence_of_events" rows="6">{{ old('sequence_of_events', $investigation->sequence_of_events) }}</x-textarea>
                </x-field>

                <x-field label="Immediate cause" name="immediate_cause"
                         help="The thing that failed or the act that was performed.">
                    <x-textarea name="immediate_cause" rows="3">{{ old('immediate_cause', $investigation->immediate_cause) }}</x-textarea>
                </x-field>

                <x-field label="Root cause" name="root_cause"
                         help="Why the immediate cause was possible. Optional while you are still working, but the investigation cannot be completed without it.">
                    <x-textarea name="root_cause" rows="4">{{ old('root_cause', $investigation->root_cause) }}</x-textarea>
                </x-field>

                <x-field label="Contributing factors" name="contributing_factors">
                    <x-textarea name="contributing_factors" rows="3">{{ old('contributing_factors', $investigation->contributing_factors) }}</x-textarea>
                </x-field>

                <x-field label="Findings" name="findings">
                    <x-textarea name="findings" rows="4">{{ old('findings', $investigation->findings) }}</x-textarea>
                </x-field>

                <x-field label="Recommendations" name="recommendations"
                         help="Each one should become a corrective action on the investigation's page.">
                    <x-textarea name="recommendations" rows="4">{{ old('recommendations', $investigation->recommendations) }}</x-textarea>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it sits</h3>
            </div>
            <div class="space-y-5 p-5">
                @if ($investigation->incident)
                    <div>
                        <p class="label">Into</p>
                        <p class="mt-1 text-sm text-[var(--text-body)]">
                            <span class="font-mono">{{ $investigation->incident->number }}</span>
                            — {{ $investigation->incident->title }}
                        </p>
                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                            An investigation cannot be moved to a different incident.
                        </p>
                    </div>
                @endif

                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $investigation->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $investigation->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $investigation->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="Investigations name people. This is usually the tightest setting on the record.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $investigation->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
