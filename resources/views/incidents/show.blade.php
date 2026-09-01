{{--
    One incident.

    THE MEDICAL COLUMNS ARE RENDERED OR THEY ARE NOT — there is no greyed-out version. `$canViewMedical`
    is a policy answer the controller already asked for, and when it is false the markup for injury,
    body part, treatment, days lost and return-to-work does not exist on the page at all. A hidden
    column is still a column somebody reads out of the page source (§6, §68, §84).

    THE INVESTIGATION AND THE ACTIONS ARE OPENED FROM HERE, and both go through the same services a
    configured workflow action calls. That is this phase's whole argument: a company that wants an
    investigation opened automatically configures a stage to do it, and nothing on this page changes.

    CLOSING IS REFUSED WHILE ACTIONS ARE OUTSTANDING — by the service, not by this page. The button is
    offered and the refusal comes back as an error, because a button that silently vanishes teaches
    nobody why they cannot close it.

    Expects: $incident, $timeline, $transitions, $workflowInstance, $canViewMedical, $canAddPeople,
             $canEdit, $canDelete, $canInvestigate, $canClose, $canReopen, $canRaiseAction,
             $actionTypes, $actionPriorities, $involvements, $treatments, $people
--}}
<x-layouts.app :title="$incident->displayName()">

    <x-page-header :title="$incident->title" :description="$incident->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('incidents.edit', $incident) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canClose && ! $incident->isClosedOut())
                <form method="POST" action="{{ route('incidents.close', $incident) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Close it out</button>
                </form>
            @endif

            <a href="{{ route('incidents.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('close') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('reason') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('investigation') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($incident->is_reportable)
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm font-medium text-rose-800 dark:text-rose-200">
                Reportable to the authorities
            </p>
            <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">
                @if ($incident->reported_to_authority_on)
                    Recorded as reported on {{ $incident->reported_to_authority_on->format('j M Y') }}.
                @else
                    Not yet recorded as having been reported.
                @endif
                @if ($incident->authority_reference)
                    Reference <span class="font-mono">{{ $incident->authority_reference }}</span>.
                @endif
            </p>
        </div>
    @endif

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif
    @if (! $workflowInstance && $canEdit && ! $incident->isClosedOut())
        {{-- Offered whether or not a route is configured: the endpoint says so plainly if one is
             not, and hiding the button would mean an administrator who has just configured one has
             no way to tell it worked. --}}
        <form method="POST" action="{{ route('incidents.submit', $incident) }}" class="mb-6">
            @csrf
            <button type="submit" class="btn btn-secondary">
                <x-icon name="flag" class="h-4 w-4"/> Send for review
            </button>
            @error('workflow')
                <p class="error mt-2" role="alert">{{ $message }}</p>
            @enderror
        </form>
    @endif


    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What happened</h3>
                    <div class="flex items-center gap-2">
                        <x-badge tone="neutral">{{ $incident->severity ?: 'Unrated' }}</x-badge>
                        @if ($incident->potential_severity && $incident->potential_severity !== $incident->severity)
                            <x-badge tone="warning">could have been {{ $incident->potential_severity }}</x-badge>
                        @endif
                    </div>
                </div>
                <div class="space-y-5 p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                        {{ $incident->description ?: 'No account of the event was recorded.' }}
                    </p>

                    @if ($incident->immediate_actions)
                        <div class="border-t border-[var(--surface-border)] pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                                Done there and then
                            </p>
                            <p class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">
                                {{ $incident->immediate_actions }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            @include('incidents.partials.people')
            @include('incidents.partials.investigation')
            @include('corrective-actions.partials.attached', [
                'record' => $incident,
                'route' => route('incidents.actions.store', $incident),
            ])

            @if ($canInvestigate)
                @include('incidents.partials.reportable')
            @endif

            @if ($canReopen && $incident->isClosedOut())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Reopen</h3>
                    </div>
                    <form method="POST" action="{{ route('incidents.reopen', $incident) }}" class="space-y-4 p-5">
                        @csrf
                        <p class="text-sm text-[var(--text-muted)]">
                            Reopening keeps everything already recorded and adds to it. The reason is
                            required and goes on the timeline — a closed incident that quietly comes
                            back open is how a register stops meaning anything.
                        </p>
                        <x-field label="Why is it being reopened" name="reason" required>
                            <x-textarea name="reason" rows="3" required>{{ old('reason') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-secondary">Reopen this incident</button>
                    </form>
                </div>
            @endif

            <x-register.transitions :action="route('incidents.transition', $incident)"
                                    :transitions="$transitions" noun="incident"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this incident since it was reported."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$incident->number" mono/>
                    <x-register.fact label="Status">
                        @if ($incident->status)
                            <x-badge :tone="$incident->status->tone()">{{ $incident->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Reported</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Category" :value="$incident->category"/>
                    <x-register.fact label="Actual severity" :value="$incident->severity"/>
                    <x-register.fact label="Potential severity" :value="$incident->potential_severity"/>
                    <x-register.fact label="Location" :value="$incident->location"/>
                    <x-register.fact label="Project" :value="$incident->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$incident->discipline?->name"/>
                    <x-register.fact label="Department" :value="$incident->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$incident->confidentiality?->label()"/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Dates and people</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Occurred" :value="$incident->occurred_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Reported" :value="$incident->reported_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Reported by" :value="$incident->reporter?->name"/>
                    <x-register.fact label="Lost time">
                        @if ($incident->is_lost_time)
                            {{ $incident->lost_time_days !== null ? $incident->lost_time_days.' days' : 'Yes' }}
                        @else
                            No
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Closed" :value="$incident->closed_at?->format('j M Y')"/>
                    <x-register.fact label="Closed by" :value="$incident->closer?->name"/>
                    <x-register.fact label="Recorded by" :value="$incident->creator?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            The incident comes off the register. The audit trail keeps what was done to
                            it — deleting the record does not delete the fact that it was reported.
                        </p>
                        <x-delete-form :action="route('incidents.destroy', $incident)"
                                       label="Delete this incident"
                                       :confirm="'Delete '.$incident->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
