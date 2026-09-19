{{--
    One contract.

    TWO PERMISSIONS DECIDE WHAT IS ON THIS PAGE. `view` got the reader here; `$canViewFinancials`
    decides whether the Money card is drawn at all. Site management routinely needs to know that a
    subcontract exists, who it is with and when it runs out while having no business knowing what it
    is worth — so the card is ABSENT rather than redacted. A card of hidden figures still tells the
    reader what they are missing and who to ask (§6, §68).

    THE DATES CARD IS THE ONE PEOPLE ACTUALLY COME FOR. Expiry, and then the defects liability date
    that follows it — the period everybody forgets and that retention is usually released at the end
    of. Both are computed; neither is a status, because a contract passes its end date whether or not
    anybody clicks anything.

    RETENTION MOVES ONE MOVEMENT AT A TIME, never by retyping a balance. Each posting carries a note
    onto the timeline and the service refuses a release that would take the balance below zero. It
    sits behind `contract.manage` rather than `contract.edit`, because it changes what last month's
    commercial report said.

    Expects: $contract, $timeline, $transitions, $workflowInstance, $warningDays, $canOpenDocument,
             $canViewFinancials, $canEdit, $canDelete, $canApprove, $canMoveRetention
--}}
<x-layouts.app :title="$contract->number">

    <x-page-header :title="$contract->title" :description="$contract->number.' · '.$contract->partyLabel()">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('contracts.edit', $contract) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('amount') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($contract->hasExpired())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm text-rose-800 dark:text-rose-200">
                Expired on {{ $contract->ends_on->format('j M Y') }}. Work instructed under it after
                that date has no contractual cover — which is a conversation to have before the
                invoice arrives, not after.
            </p>
        </div>
    @elseif ($contract->expiresWithin($warningDays))
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                Ends on {{ $contract->ends_on->format('j M Y') }} — inside the
                {{ $warningDays }}-day window your organization watches. Extensions take longer to
                agree than people plan for.
            </p>
        </div>
    @endif

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The agreement</h3>
                    @if ($contract->isClientSide())
                        <x-badge tone="success">Client · income</x-badge>
                    @else
                        <x-badge tone="neutral">Vendor · spend</x-badge>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Counterparty">
                            @if ($contract->party)
                                <a href="{{ $contract->isClientSide()
                                        ? route('clients.show', $contract->party)
                                        : route('vendors.show', $contract->party) }}"
                                   class="hover:text-brand-600">{{ $contract->party->name }}</a>
                                @if ($contract->party_name && $contract->party_name !== $contract->party->name)
                                    <span class="block text-xs text-[var(--text-muted)]">
                                        On the paper as “{{ $contract->party_name }}”
                                    </span>
                                @endif
                            @else
                                {{ $contract->partyLabel() }}
                                <span class="block text-xs text-[var(--text-muted)]">
                                    Not linked to a register record
                                </span>
                            @endif
                        </x-register.fact>

                        <x-register.fact label="Type"
                                         :value="$contract->contract_type ? Str::headline($contract->contract_type) : null"/>
                        <x-register.fact label="Their reference" :value="$contract->reference" mono/>
                        <x-register.fact label="Project">
                            @if ($contract->project)
                                <a href="{{ route('projects.show', $contract->project) }}"
                                   class="hover:text-brand-600">{{ $contract->project->name }}</a>
                            @else
                                <span class="text-[var(--text-muted)]">Not project-specific</span>
                            @endif
                        </x-register.fact>
                    </dl>

                    @if ($contract->description)
                        <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Description</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $contract->description }}</p>
                        </div>
                    @endif

                    @if ($contract->scope_summary)
                        <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Scope</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $contract->scope_summary }}</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Absent, not redacted. See the header note. --}}
            @if ($canViewFinancials)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Money</h3>
                        @if ($contract->currency)
                            <span class="font-mono text-xs text-[var(--text-muted)]">{{ $contract->currency }}</span>
                        @endif
                    </div>
                    <div class="p-5">
                        <dl class="divide-y divide-[var(--surface-border)]">
                            <x-register.fact label="Contract value"
                                             :value="$contract->value !== null ? number_format((float) $contract->value, 2) : null"
                                             mono/>
                            <x-register.fact label="Retention agreed"
                                             :value="$contract->retention_percent !== null ? rtrim(rtrim(number_format((float) $contract->retention_percent, 2), '0'), '.').'%' : null"/>
                            <x-register.fact label="Retention held now" mono>
                                @if ((float) $contract->retention_held > 0)
                                    <span class="text-amber-600">{{ number_format((float) $contract->retention_held, 2) }}</span>
                                @else
                                    <span class="text-[var(--text-muted)]">Nothing held</span>
                                @endif
                            </x-register.fact>
                            <x-register.fact label="Advance"
                                             :value="$contract->advance_percent !== null ? rtrim(rtrim(number_format((float) $contract->advance_percent, 2), '0'), '.').'%' : null"/>
                        </dl>

                        @if ($contract->payment_terms)
                            <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Payment terms</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $contract->payment_terms }}</p>
                            </div>
                        @endif
                    </div>

                    @if ($canMoveRetention)
                        <div class="border-t border-[var(--surface-border)] p-5">
                            <form method="POST" action="{{ route('contracts.retention.move', $contract) }}"
                                  class="space-y-3">
                                @csrf
                                <p class="text-sm font-medium text-[var(--text-strong)]">Move retention</p>
                                <p class="text-xs text-[var(--text-muted)]">
                                    One movement at a time, each recorded. A release that would take
                                    the balance below zero is refused rather than clamped — a
                                    negative balance means the arithmetic is wrong somewhere else.
                                </p>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label for="movement" class="label">Movement</label>
                                        <select name="movement" id="movement" class="input" required>
                                            <option value="hold">Hold back</option>
                                            <option value="release">Release</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="amount" class="label">Amount</label>
                                        <input type="number" step="0.01" min="0.01" name="amount" id="amount"
                                               class="input" required>
                                    </div>
                                </div>

                                <div>
                                    <label for="note" class="label">Note</label>
                                    <input name="note" id="note" class="input"
                                           placeholder="Against valuation 7, certified 28 August.">
                                </div>

                                <button type="submit" class="btn btn-secondary btn-sm">Post the movement</button>
                            </form>
                        </div>
                    @endif
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Dates</h3>
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Signed" :value="$contract->signed_on?->format('j M Y')"/>
                        <x-register.fact label="Starts" :value="$contract->starts_on?->format('j M Y')"/>
                        <x-register.fact label="Ends">
                            @if ($contract->ends_on)
                                <span @class([
                                    'font-medium text-rose-600' => $contract->hasExpired(),
                                    'font-medium text-amber-600' => $contract->expiresWithin($warningDays),
                                ])>{{ $contract->ends_on->format('j M Y') }}</span>
                            @else
                                <span class="text-[var(--text-muted)]">Open-ended</span>
                            @endif
                        </x-register.fact>

                        {{-- Computed, and worth stating outright: it is the date people are asked
                             about long after everybody has stopped looking at the contract. --}}
                        <x-register.fact label="Defects liability ends">
                            @if ($end = $contract->defectsLiabilityEndsOn())
                                {{ $end->format('j M Y') }}
                                <span class="text-sm text-[var(--text-muted)]">
                                    — {{ $contract->defects_liability_months }} months after completion
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">Not recorded</span>
                            @endif
                        </x-register.fact>
                    </dl>
                </div>
            </div>

            @if ($contract->notes)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Internal notes</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $contract->notes }}</p>
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Standing</h3>
                    @if ($contract->status)
                        <x-badge :tone="$contract->status->tone()">{{ $contract->status->label }}</x-badge>
                    @else
                        <x-badge tone="neutral">Draft</x-badge>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Contract owner" :value="$contract->owner?->name"/>
                        <x-register.fact label="Owning unit" :value="$contract->orgUnit?->name"/>
                        <x-register.fact label="Confidentiality" :value="$contract->confidentiality?->label()"/>
                        <x-register.fact label="Registered by" :value="$contract->creator?->name"/>

                        {{-- Linked only where the reader can open it. A link they cannot follow
                             announces the file rather than sharing it (§67, §68). --}}
                        <x-register.fact label="Signed copy">
                            @if ($canOpenDocument)
                                <a href="{{ route('documents.show', $contract->document) }}"
                                   class="hover:text-brand-600">
                                    <x-icon name="file-text" class="inline h-3.5 w-3.5"/>
                                    {{ $contract->document->number }}
                                </a>
                            @elseif ($contract->document_id)
                                <span class="text-[var(--text-muted)]">Held in the document register</span>
                            @else
                                <span class="text-[var(--text-muted)]">Not attached</span>
                            @endif
                        </x-register.fact>
                    </dl>
                </div>

                <x-register.transitions :action="route('contracts.transition', $contract)"
                                        :transitions="$transitions" noun="contract"/>
            </div>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this contract was registered."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            A contract that was superseded, terminated or completed should be moved to
                            that status rather than deleted. Deleting is for the one somebody
                            registered twice.
                        </p>
                        <x-delete-form :action="route('contracts.destroy', $contract)"
                                       label="Delete this contract"
                                       :confirm="'Delete '.$contract->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$contract" class="card mt-5"/>

</x-layouts.app>
