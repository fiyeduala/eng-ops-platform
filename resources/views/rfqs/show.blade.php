@use('App\Support\Quantity')
{{--
    One enquiry — and the page where the competition actually happens.

    THE INVITATION LIST IS EVIDENCE, so it is built here one supplier at a time and each row carries
    when they were invited, whether they answered, and whether they declined and why. That is the
    record somebody will be asked for a year from now when a losing bidder complains, and it cannot
    be reconstructed from a multi-select on a form.

    A SUPPLIER WHO CANNOT BE USED IS STILL OFFERED. Blacklisted, expired prequalification, lapsed
    insurance — the picker shows them and the service refuses them WITH THE REASON. A supplier that
    silently vanishes from a list teaches the buyer nothing; a refusal that says "prequalification
    expired on 3 March" teaches them exactly what to fix.

    ISSUING IS THE LINE. Before it, the enquiry is a draft nobody has seen. After it, the invitation
    list is the record of who was asked, and removing somebody from it is no longer housekeeping.

    AWARDING IS ONE ACT OVER EVERY QUOTATION. It happens on the comparison page, not here, because
    awarding without reading the comparison is the thing this module exists to make harder.

    Expects: $rfq, $summary, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete,
             $canIssue, $canInvite, $canAward, $canCompare, $canRecordQuotation, $vendors
--}}
<x-layouts.app :title="$rfq->number">

    <x-page-header :title="$rfq->title"
                   :description="$rfq->number.($rfq->project ? ' · '.$rfq->project->name : '')">
        <x-slot:actions>
            @if ($canCompare && $rfq->quotations->isNotEmpty())
                <a href="{{ route('rfqs.comparison', $rfq) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="activity" class="h-3.5 w-3.5"/> Compare quotations
                </a>
            @endif
            @if ($canRecordQuotation)
                <a href="{{ route('quotations.create', ['rfq' => $rfq->id]) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Record a quotation
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('rfqs.edit', $rfq) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('vendor_id') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('quotation_id') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('reason') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('status_id') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($rfq->closes_at && $rfq->closes_at->isPast() && ! $rfq->status?->is_terminal)
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                The deadline passed on {{ $rfq->closes_at->format('j M Y, H:i') }}. Closing the
                enquiry is still something somebody does — a quotation that arrives now is recorded
                late rather than refused, and the timeline will say so.
            </p>
        </div>
    @endif

    @if ($workflowInstance)
        <div class="mb-6"><x-workflow-progress :instance="$workflowInstance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The enquiry</h3>
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Project">
                            @if ($rfq->project)
                                <a href="{{ route('projects.show', $rfq->project) }}"
                                   class="hover:text-brand-600">{{ $rfq->project->name }}</a>
                            @else
                                <span class="text-[var(--text-muted)]">Not project-specific</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Against requisition">
                            @if ($rfq->purchaseRequest)
                                <a href="{{ route('purchase-requests.show', $rfq->purchaseRequest) }}"
                                   class="hover:text-brand-600">
                                    <span class="font-mono text-xs">{{ $rfq->purchaseRequest->number }}</span>
                                    — {{ $rfq->purchaseRequest->title }}
                                </a>
                            @else
                                <span class="text-[var(--text-muted)]">Raised directly</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Closes" :value="$rfq->closes_at?->format('j M Y, H:i')"/>
                        <x-register.fact label="Issued">
                            @if ($rfq->issued_at)
                                {{ $rfq->issued_at->format('j M Y, H:i') }}
                                <span class="block text-xs text-[var(--text-muted)]">
                                    by {{ $rfq->issuer?->name ?? 'somebody' }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">Not yet issued</span>
                            @endif
                        </x-register.fact>
                    </dl>

                    @foreach (['Scope' => $rfq->scope, 'Terms' => $rfq->terms, 'Internal notes' => $rfq->notes] as $heading => $body)
                        @if ($body)
                            <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">{{ $heading }}</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $body }}</p>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Invited suppliers</h3>
                    <span class="text-xs text-[var(--text-muted)]">
                        {{ $summary['responded'] }} of {{ $summary['invited'] }} answered
                        @if ($summary['declined'] > 0) · {{ $summary['declined'] }} declined @endif
                    </span>
                </div>

                @if ($rfq->invitations->isEmpty())
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        Nobody has been invited yet. An enquiry issued to nobody is a document rather
                        than an enquiry.
                    </p>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($rfq->invitations as $invitation)
                            <li class="flex flex-wrap items-start justify-between gap-3 p-4">
                                <div>
                                    <p class="text-sm font-medium text-[var(--text-strong)]">
                                        {{ $invitation->vendor?->name ?? 'Supplier removed from the register' }}
                                    </p>
                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                        @if ($invitation->invited_at)
                                            Invited {{ $invitation->invited_at->format('j M Y, H:i') }}
                                        @else
                                            Not yet sent
                                        @endif
                                        @if ($invitation->responded_at)
                                            · answered {{ $invitation->responded_at->format('j M Y') }}
                                        @endif
                                    </p>
                                    @if ($invitation->declined_at)
                                        <p class="mt-1 text-xs text-amber-600">
                                            Declined {{ $invitation->declined_at->format('j M Y') }}
                                            @if ($invitation->decline_reason) — {{ $invitation->decline_reason }} @endif
                                        </p>
                                    @endif
                                </div>

                                <div class="flex items-center gap-2">
                                    @if ($invitation->hasResponded())
                                        <x-badge tone="success">Quoted</x-badge>
                                    @elseif ($invitation->hasDeclined())
                                        <x-badge tone="warning">Declined</x-badge>
                                    @else
                                        <x-badge tone="neutral">Silent</x-badge>
                                    @endif

                                    {{-- Recording a decline is a fact about the supplier, and
                                         withdrawing an invitation is housekeeping — both are
                                         offered only while the supplier has neither quoted nor
                                         already declined, and both are refused server-side once
                                         they have (§84). --}}
                                    @if ($canInvite && $invitation->isSilent())
                                        <form method="POST"
                                              action="{{ route('rfqs.invitations.decline', [$rfq, $invitation]) }}"
                                              class="flex items-center gap-1">
                                            @csrf
                                            <input name="comment" class="input py-1 text-xs"
                                                   placeholder="Reason they gave">
                                            <button type="submit" class="btn btn-ghost btn-sm">Declined</button>
                                        </form>

                                        <x-delete-form :action="route('rfqs.invitations.destroy', [$rfq, $invitation])"
                                                       label="Withdraw"
                                                       :confirm="'Withdraw the invitation to '.($invitation->vendor?->name ?? 'this supplier').'?'"/>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($canInvite)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Invite another supplier</h3>
                    </div>
                    <form method="POST" action="{{ route('rfqs.invitations.store', $rfq) }}"
                          class="space-y-4 p-5">
                        @csrf

                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-field label="Supplier" name="vendor_id" required>
                                <x-select name="vendor_id" :options="$vendors"
                                          placeholder="Choose a supplier" required/>
                            </x-field>

                            <x-field label="Note" name="notes"
                                     help="Anything specific to this supplier's invitation.">
                                <x-input name="notes" placeholder="Asked to quote the alternative as well"/>
                            </x-field>
                        </div>

                        <p class="text-xs text-[var(--text-muted)]">
                            Suppliers you cannot currently order from are still listed. Choosing one
                            gets you the reason — expired prequalification, blacklisted, lapsed
                            insurance — rather than a supplier that quietly is not there.
                        </p>

                        <button type="submit" class="btn btn-secondary btn-sm">Invite</button>
                    </form>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Quotations received</h3>
                    @if ($canCompare && $rfq->quotations->isNotEmpty())
                        <a href="{{ route('rfqs.comparison', $rfq) }}" class="text-xs hover:text-brand-600">
                            Compare them →
                        </a>
                    @endif
                </div>

                @if ($rfq->quotations->isEmpty())
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        Nothing has come back yet.
                    </p>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($rfq->quotations as $quotation)
                            <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                                <a href="{{ route('quotations.show', $quotation) }}"
                                   class="text-sm hover:text-brand-600">
                                    <span class="font-medium text-[var(--text-strong)]">
                                        {{ $quotation->vendor?->name ?? 'Supplier' }}
                                    </span>
                                    <span class="block font-mono text-xs text-[var(--text-muted)]">
                                        {{ $quotation->number }}
                                    </span>
                                </a>

                                <div class="flex items-center gap-3">
                                    {{-- Figures are shown because getting here already required
                                         `quotation.view` through the scoped relation. --}}
                                    <span class="font-mono text-sm text-[var(--text-body)]">
                                        {{ $quotation->currency }} {{ number_format((float) $quotation->total, 2) }}
                                    </span>
                                    @if ($quotation->status)
                                        <x-badge :tone="$quotation->status->tone()">{{ $quotation->status->label }}</x-badge>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Lines quoted against</h3>
                    <span class="text-xs text-[var(--text-muted)]">
                        {{ $rfq->items->count() }} {{ Str::plural('line', $rfq->items->count()) }}
                    </span>
                </div>

                @if ($rfq->items->isEmpty())
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        Nothing has been listed. Suppliers need something to price.
                    </p>
                @else
                    <ul class="divide-y divide-[var(--surface-border)] text-sm">
                        @foreach ($rfq->items as $item)
                            <li class="flex items-start justify-between gap-4 px-5 py-3">
                                <span>
                                    <span class="text-xs text-[var(--text-muted)]">{{ $item->line_no }}.</span>
                                    <span class="text-[var(--text-body)]">{{ $item->description }}</span>
                                    @if ($item->specification)
                                        <span class="block pl-5 text-xs text-[var(--text-muted)]">{{ $item->specification }}</span>
                                    @endif
                                </span>
                                <span class="whitespace-nowrap font-mono text-xs text-[var(--text-muted)]">
                                    {{ Quantity::withUnit($item->quantity, $item->uom) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Standing</h3>
                    @if ($rfq->status)
                        <x-badge :tone="$rfq->status->tone()">{{ $rfq->status->label }}</x-badge>
                    @else
                        <x-badge tone="neutral">Draft</x-badge>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Raised by" :value="$rfq->creator?->name"/>
                        <x-register.fact label="Owning unit" :value="$rfq->orgUnit?->name"/>
                        <x-register.fact label="Confidentiality" :value="$rfq->confidentiality?->label()"/>
                        <x-register.fact label="Awarded to">
                            @if ($rfq->awardedQuotation)
                                <a href="{{ route('quotations.show', $rfq->awardedQuotation) }}"
                                   class="hover:text-brand-600">{{ $rfq->awardedQuotation->number }}</a>
                                <span class="block font-mono text-xs text-[var(--text-muted)]">
                                    {{ $rfq->awardedQuotation->currency }}
                                    {{ number_format((float) $rfq->awardedQuotation->total, 2) }}
                                </span>
                                <span class="block text-xs text-[var(--text-muted)]">
                                    {{ $rfq->awarded_at?->format('j M Y') }} by {{ $rfq->awarder?->name ?? 'somebody' }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">Not yet awarded</span>
                            @endif
                        </x-register.fact>
                        @if ($rfq->award_reason)
                            <x-register.fact label="Why" :value="$rfq->award_reason"/>
                        @endif
                    </dl>
                </div>

                {{-- Issuing has its own endpoint and its own permission because it is the moment the
                     enquiry stops being private. It is not one of the generic status moves. --}}
                @if ($canIssue)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('rfqs.issue', $rfq) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm font-medium text-[var(--text-strong)]">Issue the enquiry</p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Stamps every invitation as sent and fixes the list as the record of
                                who was asked. An enquiry with nobody invited cannot be issued.
                            </p>
                            <x-input name="comment" placeholder="Optional note for the timeline"/>
                            <button type="submit" class="btn btn-primary btn-sm w-full">Issue to the invited suppliers</button>
                        </form>
                    </div>
                @endif

                @if ($canAward && $rfq->quotations->isNotEmpty() && ! $rfq->awardedQuotation)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <p class="text-sm font-medium text-[var(--text-strong)]">Award</p>
                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                            Awarding sets the winner and every loser in one act, so the two can never
                            disagree. It is done from the comparison, where the figures are side by
                            side and the reason has somewhere to come from.
                        </p>
                        <a href="{{ route('rfqs.comparison', $rfq) }}" class="btn btn-secondary btn-sm mt-3 w-full">
                            Open the comparison
                        </a>
                    </div>
                @endif

                <x-register.transitions :action="route('rfqs.transition', $rfq)"
                                        :transitions="$transitions" noun="enquiry"/>
            </div>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this enquiry was raised."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            An enquiry that came to nothing should be cancelled with a reason — the
                            suppliers who quoted are entitled to know it ended. Deleting is for the
                            one raised in error.
                        </p>
                        <x-delete-form :action="route('rfqs.destroy', $rfq)"
                                       label="Delete this enquiry"
                                       :confirm="'Delete '.$rfq->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$rfq" class="card mt-5"/>

</x-layouts.app>
