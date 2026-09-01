{{--
    One observation.

    THE CARD IS SHORT AND THE PAGE STAYS SHORT. Everything a near miss needs is on one screen: what
    was seen, what was done about it there and then, and what has been raised since.

    CLOSING IS REFUSED WHILE AN ACTION IS OUTSTANDING — by the service. A card closed with an open
    action against it is the exact bookkeeping that makes a safety register stop being believed.

    THE REPORTER'S NAME IS WHATEVER `observerLabel()` SAYS, and for an anonymous card that is
    "Anonymous" because no id was ever written. There is nothing on this page, or behind it, that
    could resolve it to a person.

    Expects: $observation, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete,
             $canClose, $canReopen, $canRaiseAction, $actionTypes, $actionPriorities, $people
--}}
<x-layouts.app :title="$observation->displayName()">

    <x-page-header :title="$observation->title" :description="$observation->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('hse-observations.edit', $observation) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            <a href="{{ route('hse-observations.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('close') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('reason') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What was seen</h3>
                    <div class="flex items-center gap-2">
                        @if ($observation->observation_type)
                            <x-badge tone="neutral">{{ $observation->observation_type }}</x-badge>
                        @endif
                        @if ($observation->risk_level)
                            <x-badge :tone="in_array(strtolower($observation->risk_level), ['high', 'critical'], true) ? 'danger' : 'neutral'">
                                {{ $observation->risk_level }} risk
                            </x-badge>
                        @endif
                    </div>
                </div>
                <div class="space-y-5 p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                        {{ $observation->description ?: 'Nothing beyond the title was recorded — which is fine. A thin card beats no card.' }}
                    </p>

                    @if ($observation->immediate_action)
                        <div class="border-t border-[var(--surface-border)] pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                                Done there and then
                            </p>
                            <p class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">
                                {{ $observation->immediate_action }}
                            </p>
                        </div>
                    @endif

                    @if ($observation->action_required && $observation->correctiveActions->isEmpty())
                        <p class="text-sm text-amber-700 dark:text-amber-400">
                            This card is flagged as needing something done, and nothing has been
                            raised against it yet.
                        </p>
                    @endif
                </div>
            </div>

            @include('corrective-actions.partials.attached', [
                'record' => $observation,
                'route' => route('hse-observations.actions.store', $observation),
            ])

            @if ($canClose && ! $observation->isClosedOut())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Close it out</h3>
                    </div>
                    <form method="POST" action="{{ route('hse-observations.close', $observation) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <x-field label="Closing note" name="notes"
                                 help="Optional, but the person who reported it deserves to know what happened.">
                            <x-textarea name="notes" rows="3">{{ old('notes') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-primary">Close the card</button>
                    </form>
                </div>
            @endif

            @if ($canReopen && $observation->isClosedOut())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Reopen</h3>
                    </div>
                    <form method="POST" action="{{ route('hse-observations.reopen', $observation) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <x-field label="Why is it being reopened" name="reason" required>
                            <x-textarea name="reason" rows="3" required>{{ old('reason') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-secondary">Reopen the card</button>
                    </form>
                </div>
            @endif

            <x-register.transitions :action="route('hse-observations.transition', $observation)"
                                    :transitions="$transitions" noun="observation"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this card was filed."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$observation->number" mono/>
                    <x-register.fact label="Status">
                        @if ($observation->status)
                            <x-badge :tone="$observation->status->tone()">{{ $observation->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Reported by" :value="$observation->observerLabel()"/>
                    <x-register.fact label="Seen" :value="$observation->observed_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Where" :value="$observation->location"/>
                    <x-register.fact label="Type" :value="$observation->observation_type"/>
                    <x-register.fact label="Category" :value="$observation->category"/>
                    <x-register.fact label="Risk" :value="$observation->risk_level"/>
                    <x-register.fact label="Project" :value="$observation->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$observation->discipline?->name"/>
                    <x-register.fact label="Department" :value="$observation->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$observation->confidentiality?->label()"/>
                    <x-register.fact label="Closed" :value="$observation->closed_at?->format('j M Y')"/>
                    <x-register.fact label="Closed by" :value="$observation->closer?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Deleting a near-miss card removes a free warning from the record. Closing
                            it out is nearly always the right act instead.
                        </p>
                        <x-delete-form :action="route('hse-observations.destroy', $observation)"
                                       label="Delete this card"
                                       :confirm="'Delete '.$observation->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
