{{--
    One change: what it is, what it costs, and what was decided.

    THE DECISION CARD IS THE PAGE. Everything above it exists to be read before pressing one of
    those buttons, and everything below it is history. So the impact figures sit inside the
    decision form rather than beside it — the moment somebody decides is very often the first
    moment the change has actually been priced, and asking them to leave the page, edit the record
    and come back is how a register fills up with approved changes worth nothing.

    ONCE DECIDED, NOTHING ON THIS PAGE CAN BE EDITED. Not the description, not the cost, not the
    reason. The policy refuses `update` on a decided request and the service refuses the protected
    columns, so a change approved at one figure cannot quietly become a change at another (§59).

    THE AUTHOR CANNOT DECIDE THEIR OWN. That refusal lives in the policy, not here — this page
    simply will not render the card. Hiding it is a courtesy; the refusal is server-side (§6).

    Expects: $changeRequest, $timeline, $transitions, $dispositions, $workflowInstance,
             $canEdit, $canDelete, $canSubmit, $canDecide, $canClose
--}}
<x-layouts.app :title="$changeRequest->displayName()">

    <x-page-header :title="$changeRequest->title" :description="$changeRequest->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('change-requests.edit', $changeRequest) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canSubmit && ! $changeRequest->isDecided())
                <form method="POST" action="{{ route('change-requests.submit', $changeRequest) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Submit for decision</button>
                </form>
            @endif

            @if ($canClose && $changeRequest->isDecided())
                <form method="POST" action="{{ route('change-requests.close', $changeRequest) }}">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">Close it out</button>
                </form>
            @endif

            <a href="{{ route('change-requests.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('submit') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('close') <p class="error mb-4">{{ $message }}</p> @enderror

    {{-- ---------------------------------------------------------------- the answer --}}
    @if ($changeRequest->isDecided())
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <div class="flex flex-wrap items-center gap-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-sm font-medium ring-1 ring-inset {{ $changeRequest->disposition?->badgeClasses() }}">
                    {{ $changeRequest->disposition?->label() ?? 'Decided' }}
                </span>
                <span class="text-sm text-[var(--text-body)]">
                    by {{ $changeRequest->decider?->name ?? 'a decision-maker' }}
                    on {{ $changeRequest->decided_at?->format('j M Y') }}
                </span>
            </div>

            @if ($changeRequest->decision_notes)
                <p class="mt-3 whitespace-pre-line text-sm text-[var(--text-body)]">
                    {{ $changeRequest->decision_notes }}
                </p>
            @endif

            <p class="mt-3 text-xs text-[var(--text-muted)]">
                A decided change is frozen — description, reason and impact included. If the answer
                needs to change, raise a new request against this one rather than rewriting history.
            </p>
        </div>
    @endif

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================================================ main column --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- ------------------------------------------------------- the decision --}}
            @if ($canDecide && ! $changeRequest->isDecided())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Decide</h3>
                    </div>

                    <form method="POST" action="{{ route('change-requests.decide', $changeRequest) }}"
                          class="space-y-5 p-5">
                        @csrf

                        <p class="text-sm text-[var(--text-muted)]">
                            Anything other than an approval has to say why. This is the last moment the
                            impact figures can be set — after a decision they are frozen, so if the
                            change has been priced since it was raised, correct the numbers here.
                        </p>

                        <x-field label="Decision" name="disposition" required>
                            <x-select name="disposition" :options="$dispositions" required
                                      placeholder="Choose"/>
                        </x-field>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-field label="Cost impact" name="cost_impact"
                                     help="Leave blank to keep what the request already carries. Negative for a saving.">
                                <x-input type="number" name="cost_impact" step="0.01"
                                         :value="old('cost_impact', $changeRequest->cost_impact)"/>
                            </x-field>

                            <x-field label="Schedule impact (days)" name="schedule_impact_days"
                                     help="Leave blank to keep the figure already on the request.">
                                <x-input type="number" name="schedule_impact_days" step="1"
                                         :value="old('schedule_impact_days', $changeRequest->schedule_impact_days)"/>
                            </x-field>
                        </div>

                        <x-field label="Decision notes" name="decision_notes"
                                 help="Required for anything other than an approval — a rejection nobody explained is a rejection somebody will re-raise next month.">
                            <x-textarea name="decision_notes" rows="4">{{ old('decision_notes') }}</x-textarea>
                        </x-field>

                        @error('disposition') <p class="error">{{ $message }}</p> @enderror

                        <div class="flex items-center gap-3">
                            <button type="submit" class="btn btn-primary">Record the decision</button>
                            <span class="help">This cannot be undone.</span>
                        </div>
                    </form>
                </div>
            @endif

            {{-- ------------------------------------------------------- the change --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What is changing</h3>
                </div>

                <div class="space-y-5 p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                        {{ $changeRequest->description }}
                    </p>

                    @if ($changeRequest->reason)
                        <div class="border-t border-[var(--surface-border)] pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Why</p>
                            <p class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">
                                {{ $changeRequest->reason }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ------------------------------------------------------- impact --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Impact</h3>
                </div>

                <div class="p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-md border border-[var(--surface-border)] p-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Cost</p>
                            <p @class([
                                'mt-1 font-mono text-xl',
                                'text-rose-600' => $changeRequest->cost_impact !== null && (float) $changeRequest->cost_impact > 0,
                                'text-emerald-600' => $changeRequest->cost_impact !== null && (float) $changeRequest->cost_impact < 0,
                                'text-[var(--text-muted)]' => $changeRequest->cost_impact === null,
                            ])>
                                {{ $changeRequest->cost_impact === null
                                    ? 'Not priced'
                                    : number_format((float) $changeRequest->cost_impact, 2) }}
                            </p>
                        </div>

                        <div class="rounded-md border border-[var(--surface-border)] p-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Programme</p>
                            <p @class([
                                'mt-1 font-mono text-xl',
                                'text-rose-600' => $changeRequest->schedule_impact_days !== null && $changeRequest->schedule_impact_days > 0,
                                'text-emerald-600' => $changeRequest->schedule_impact_days !== null && $changeRequest->schedule_impact_days < 0,
                                'text-[var(--text-muted)]' => $changeRequest->schedule_impact_days === null,
                            ])>
                                {{ $changeRequest->schedule_impact_days === null
                                    ? 'Unassessed'
                                    : ($changeRequest->schedule_impact_days > 0 ? '+' : '')
                                        .$changeRequest->schedule_impact_days.' days' }}
                            </p>
                        </div>
                    </div>

                    @if ($changeRequest->impact_notes)
                        <p class="mt-4 whitespace-pre-line text-sm text-[var(--text-body)]">
                            {{ $changeRequest->impact_notes }}
                        </p>
                    @endif

                    {{-- No currency symbol anywhere on this page. The company's books say what the
                         figure is in, and a symbol this platform guessed would be worse than none (§47). --}}
                    <p class="mt-4 text-xs text-[var(--text-muted)]">
                        Figures are in your organization's own currency.
                    </p>
                </div>
            </div>

            <x-register.transitions :action="route('change-requests.transition', $changeRequest)"
                                    :transitions="$transitions" noun="change request"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this change yet."/>
        </div>

        {{-- ============================================================ side column --}}
        <div class="space-y-6">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$changeRequest->number" mono/>
                    <x-register.fact label="Origin" :value="$changeRequest->originLabel()"/>
                    <x-register.fact label="Status">
                        @if ($changeRequest->status)
                            <x-badge :tone="$changeRequest->status->tone()">{{ $changeRequest->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Project" :value="$changeRequest->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$changeRequest->discipline?->name"/>
                    <x-register.fact label="Department" :value="$changeRequest->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$changeRequest->confidentiality?->label()"/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Dates and people</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Raised by" :value="$changeRequest->raiser?->name"/>
                    <x-register.fact label="Raised on" :value="$changeRequest->raised_on?->format('j M Y')"/>
                    <x-register.fact label="Decision due" :value="$changeRequest->required_by?->format('j M Y')"/>
                    <x-register.fact label="Decided by" :value="$changeRequest->decider?->name"/>
                    <x-register.fact label="Decided on" :value="$changeRequest->decided_at?->format('j M Y')"/>
                    <x-register.fact label="Recorded by" :value="$changeRequest->creator?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            The request comes off the register. Its history is kept — deleting a change
                            does not delete the record that somebody raised it.
                        </p>
                        <x-delete-form :action="route('change-requests.destroy', $changeRequest)"
                                       label="Delete this change request"
                                       :confirm="'Delete '.$changeRequest->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
