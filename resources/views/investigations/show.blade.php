{{--
    One investigation.

    THE COMPLETE BUTTON IS OFFERED EVEN WHEN IT WILL BE REFUSED. Completing without a root cause is
    blocked by the policy and again by the service, and the refusal comes back as an error naming the
    reason. A button that quietly disappears teaches the investigator nothing; a refusal that says
    "there is no root cause" teaches them the rule once and for good.

    THE RECOMMENDATIONS AND THE ACTIONS SIT ON THE SAME PAGE, in that order, because a recommendation
    that never becomes somebody's job by a date is where investigations go to die. Raising the action
    from here is one click, and it goes through the same service a configured workflow action calls.

    Expects: $investigation, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete,
             $canComplete, $canReopen, $canRaiseAction, $actionTypes, $actionPriorities, $people
--}}
<x-layouts.app :title="$investigation->displayName()">

    <x-page-header :title="$investigation->title" :description="$investigation->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('investigations.edit', $investigation) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            <a href="{{ route('investigations.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('complete') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('reason') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            @if ($investigation->incident)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Into</h3>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 p-5">
                        <div>
                            <a href="{{ route('incidents.show', $investigation->incident) }}"
                               class="font-mono text-sm font-medium text-[var(--brand-600)] hover:underline">
                                {{ $investigation->incident->number }}
                            </a>
                            <p class="mt-0.5 text-sm text-[var(--text-body)]">
                                {{ $investigation->incident->title }}
                            </p>
                        </div>
                        <p class="text-xs text-[var(--text-muted)]">
                            {{ $investigation->incident->occurred_at?->format('j M Y H:i') }}
                            @if ($investigation->incident->severity)
                                · {{ $investigation->incident->severity }}
                            @endif
                        </p>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What was found</h3>
                    @if ($investigation->hasRootCause())
                        <x-badge tone="success">Root cause recorded</x-badge>
                    @else
                        <x-badge tone="warning">No root cause yet</x-badge>
                    @endif
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    @foreach ([
                        'Sequence of events' => $investigation->sequence_of_events,
                        'Immediate cause' => $investigation->immediate_cause,
                        'Root cause' => $investigation->root_cause,
                        'Contributing factors' => $investigation->contributing_factors,
                        'Findings' => $investigation->findings,
                        'Recommendations' => $investigation->recommendations,
                    ] as $label => $body)
                        <div class="px-5 py-4">
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                                {{ $label }}
                            </dt>
                            <dd class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">
                                {{ $body ?: '—' }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            @include('corrective-actions.partials.attached', [
                'record' => $investigation,
                'route' => route('investigations.actions.store', $investigation),
            ])

            @if ($canComplete && ! $investigation->isComplete())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Complete it</h3>
                    </div>
                    <form method="POST" action="{{ route('investigations.complete', $investigation) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <p class="text-sm text-[var(--text-muted)]">
                            Completing records who signed the investigation off and when. It is
                            refused while there is no root cause — that refusal is the only reason
                            this module exists.
                        </p>
                        <x-field label="Closing findings" name="findings"
                                 help="Optional. Anything typed here replaces what is on the record.">
                            <x-textarea name="findings" rows="4">{{ old('findings') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-primary">Complete the investigation</button>
                    </form>
                </div>
            @endif

            @if ($canReopen && $investigation->isComplete())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Reopen</h3>
                    </div>
                    <form method="POST" action="{{ route('investigations.reopen', $investigation) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <x-field label="Why is it being reopened" name="reason" required>
                            <x-textarea name="reason" rows="3" required>{{ old('reason') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-secondary">Reopen the investigation</button>
                    </form>
                </div>
            @endif

            <x-register.transitions :action="route('investigations.transition', $investigation)"
                                    :transitions="$transitions" noun="investigation"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this investigation was opened."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$investigation->number" mono/>
                    <x-register.fact label="Status">
                        @if ($investigation->status)
                            <x-badge :tone="$investigation->status->tone()">{{ $investigation->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Method" :value="$investigation->method"/>
                    <x-register.fact label="Led by" :value="$investigation->lead?->name"/>
                    <x-register.fact label="Started" :value="$investigation->started_on?->format('j M Y')"/>
                    <x-register.fact label="Due" :value="$investigation->due_on?->format('j M Y')"/>
                    <x-register.fact label="Completed" :value="$investigation->completed_on?->format('j M Y')"/>
                    <x-register.fact label="Project" :value="$investigation->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$investigation->discipline?->name"/>
                    <x-register.fact label="Department" :value="$investigation->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$investigation->confidentiality?->label()"/>
                    <x-register.fact label="Opened by" :value="$investigation->creator?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Deleting returns you to the incident, which keeps the record that an
                            investigation was once opened into it.
                        </p>
                        <x-delete-form :action="route('investigations.destroy', $investigation)"
                                       label="Delete this investigation"
                                       :confirm="'Delete '.$investigation->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
