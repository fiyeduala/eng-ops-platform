{{--
    One supplier.

    THE BANNER ANSWERS THE ONLY QUESTION MOST PEOPLE OPEN THIS PAGE WITH: can we raise a purchase
    order to this company today. Everything else on the page explains that answer.

    BANK DETAILS ARE BEHIND A SEPARATE PERMISSION, AND THE BLOCK ITSELF IS CONDITIONAL — not just its
    values. A card headed "Bank details" showing dashes still tells a reader the details exist and
    that somebody has them; the point of `vendor.bank` is that reading account numbers is a
    deliberate act by a named person, so people without it are not shown the shape of what they are
    missing (§6, §67). Where the block does render, the number is masked until the reader asks for
    it — enough to confirm they are looking at the right supplier, not enough to screenshot.

    BLACKLISTING AND LIFTING BOTH DEMAND A REASON, in the form and again in the service. Two checks
    for one rule, because this is also reachable from a workflow action and a validator only guards
    the door it is nailed to (§84).

    CONTRACTS ARE FILTERED BY WHAT THE READER MAY OPEN, not by what the vendor owns. A contract
    defaults to confidential; an unfiltered list here would turn a supplier page into a schedule of
    what every subcontractor is being paid (§61, §68).

    Expects: $vendor, $contracts, $timeline, $transitions, $workflowInstance, $warningDays,
             $canEdit, $canDelete, $canViewBank, $canEditBank, $canBlacklist, $canLiftBlacklist,
             $canPrequalify, $canEvaluate
--}}
<x-layouts.app :title="$vendor->name">

    <x-page-header :title="$vendor->name" :description="$vendor->number.($vendor->code ? ' · '.$vendor->code : '')">
        <x-slot:actions>
            @if ($canEditBank)
                <a href="{{ route('vendors.bank.edit', $vendor) }}" class="btn btn-ghost btn-sm">
                    <x-icon name="lock" class="h-3.5 w-3.5"/> Bank details
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('vendors.edit', $vendor) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('reason') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('score') <p class="error mb-4">{{ $message }}</p> @enderror

    {{-- Read in the order a buyer would: barred first, then lapsed, then merely not approved. --}}
    @if ($vendor->is_blacklisted)
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm text-rose-800 dark:text-rose-200">
                <strong>Blacklisted</strong>
                @if ($vendor->blacklisted_at) on {{ $vendor->blacklisted_at->format('j M Y') }} @endif
                @if ($vendor->blacklister) by {{ $vendor->blacklister->name }} @endif.
                No new work is to be placed with this company.
            </p>
            @if ($vendor->blacklist_reason)
                <p class="mt-1 whitespace-pre-line text-sm text-rose-700 dark:text-rose-300">
                    {{ $vendor->blacklist_reason }}
                </p>
            @endif
        </div>
    @elseif ($vendor->prequalificationHasExpired())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm text-rose-800 dark:text-rose-200">
                Prequalification expired on
                {{ $vendor->prequalification_expires_on->format('j M Y') }}. Reassess before placing
                further orders.
            </p>
        </div>
    @elseif ($vendor->prequalificationExpiresWithin($warningDays))
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                Prequalification expires on
                {{ $vendor->prequalification_expires_on->format('j M Y') }} — inside the
                {{ $warningDays }}-day window your organization watches. Reassessment usually takes
                longer than people expect.
            </p>
        </div>
    @elseif ($reason = $vendor->unusableReason())
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm text-[var(--text-body)]">
                {{ $reason }} — orders cannot be placed with this company as things stand.
            </p>
        </div>
    @else
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/60 dark:bg-emerald-950/40">
            <p class="text-sm text-emerald-800 dark:text-emerald-200">
                Available to order from.
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
                    <h3 class="card-title">The company</h3>
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Registered name" :value="$vendor->legal_name"/>
                        <x-register.fact label="Trade name" :value="$vendor->trade_name"/>
                        <x-register.fact label="Type"
                                         :value="$vendor->vendor_type ? Str::headline($vendor->vendor_type) : null"/>
                        <x-register.fact label="Category"
                                         :value="$vendor->category ? Str::headline($vendor->category) : null"/>
                        <x-register.fact label="Registration number" :value="$vendor->registration_no" mono/>
                        <x-register.fact label="Tax identifier" :value="$vendor->tax_id" mono/>

                        <x-register.fact label="Email">
                            @if ($vendor->email)
                                <a href="mailto:{{ $vendor->email }}" class="hover:text-brand-600">{{ $vendor->email }}</a>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </x-register.fact>

                        <x-register.fact label="Phone" :value="$vendor->phone"/>

                        <x-register.fact label="Website">
                            @if ($vendor->website)
                                <a href="{{ $vendor->website }}" target="_blank" rel="noopener noreferrer"
                                   class="hover:text-brand-600">{{ $vendor->website }}</a>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </x-register.fact>

                        <x-register.fact label="Address"
                                         :value="collect([$vendor->address, $vendor->city, $vendor->state, $vendor->country])->filter()->join(', ') ?: null"/>
                    </dl>

                    @if ($vendor->notes)
                        <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Notes</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $vendor->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>

            @include('partials.party-contacts', [
                'contacts' => $vendor->contacts,
                'canEdit' => $canEdit,
                'storeRoute' => route('vendors.contacts.store', $vendor),
                'updateRoutePrefix' => 'vendor-contacts',
                'destroyRoutePrefix' => 'vendor-contacts',
                'partyName' => $vendor->name,
            ])

            {{-- The block itself is conditional, not just its contents. See the header note. --}}
            @if ($canViewBank)
                <div class="card" x-data="{ revealed: false }">
                    <div class="card-header">
                        <h3 class="card-title">Bank details</h3>
                        @if ($canEditBank)
                            <a href="{{ route('vendors.bank.edit', $vendor) }}"
                               class="text-xs text-[var(--text-muted)] hover:text-brand-600">Change</a>
                        @endif
                    </div>
                    <div class="p-5">
                        @if ($vendor->hasBankDetails())
                            <dl class="divide-y divide-[var(--surface-border)]">
                                <x-register.fact label="Bank" :value="$vendor->bank_name"/>
                                <x-register.fact label="Branch" :value="$vendor->bank_branch"/>
                                <x-register.fact label="Account name" :value="$vendor->bank_account_name"/>

                                {{-- Masked until asked for. Enough to confirm the right supplier at a
                                     glance; not enough to be useful in a screenshot taken over a
                                     shoulder. --}}
                                <x-register.fact label="Account number" mono>
                                    <span x-show="! revealed">{{ $vendor->maskedAccountNumber() }}</span>
                                    <span x-show="revealed" x-cloak>{{ $vendor->bank_account_number }}</span>
                                    <button type="button" class="ml-2 text-xs font-sans text-[var(--text-muted)] hover:text-brand-600"
                                            x-on:click="revealed = ! revealed"
                                            x-text="revealed ? 'Hide' : 'Show'">Show</button>
                                </x-register.fact>

                                <x-register.fact label="SWIFT / sort / routing" :value="$vendor->bank_swift" mono/>
                            </dl>

                            <p class="mt-4 text-xs text-[var(--text-muted)]">
                                Never act on a request to change these that arrives by email. Confirm
                                by telephone, on a number you already held.
                            </p>
                        @else
                            <p class="text-sm text-[var(--text-muted)]">
                                No bank details are held. Payments to this supplier cannot be raised
                                until somebody with the right permission records them.
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Contracts</h3>
                </div>
                @if ($contracts->isEmpty())
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            No contracts with this vendor that you can open.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Number</th>
                                    <th>Title</th>
                                    <th>Ends</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contracts as $contract)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <a href="{{ route('contracts.show', $contract) }}"
                                               class="font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                                                {{ $contract->number }}
                                            </a>
                                        </td>
                                        <td>{{ $contract->title }}</td>
                                        <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                                            {{ $contract->ends_on?->format('j M Y') ?: '—' }}
                                        </td>
                                        <td class="whitespace-nowrap">
                                            @if ($contract->status)
                                                <x-badge :tone="$contract->status->tone()">{{ $contract->status->label }}</x-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Standing</h3>
                    @if ($vendor->status)
                        <x-badge :tone="$vendor->status->tone()">{{ $vendor->status->label }}</x-badge>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Prequalified on"
                                         :value="$vendor->prequalified_on?->format('j M Y')"/>
                        <x-register.fact label="Expires"
                                         :value="$vendor->prequalification_expires_on?->format('j M Y')"/>
                        <x-register.fact label="Rating">
                            @if ($vendor->rated_count)
                                {{ number_format((float) $vendor->rating, 1) }} out of 5
                                <span class="text-sm text-[var(--text-muted)]">
                                    from {{ $vendor->rated_count }} {{ Str::plural('assessment', $vendor->rated_count) }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">Never assessed</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Payment terms"
                                         :value="$vendor->payment_terms_days !== null ? $vendor->payment_terms_days.' days' : null"/>
                        <x-register.fact label="Credit limit"
                                         :value="$vendor->credit_limit !== null ? number_format((float) $vendor->credit_limit, 2) : null"
                                         mono/>
                        <x-register.fact label="Owning unit" :value="$vendor->orgUnit?->name"/>
                        <x-register.fact label="Confidentiality" :value="$vendor->confidentiality?->label()"/>
                        <x-register.fact label="Added by" :value="$vendor->creator?->name"/>
                    </dl>
                </div>

                @if ($canPrequalify)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('vendors.prequalify', $vendor) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm font-medium text-[var(--text-strong)]">Record a prequalification</p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Filing the assessment. Approving the supplier on the strength of it is
                                the status move below.
                            </p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="prequalified_on" class="label">Assessed on</label>
                                    <input type="date" name="prequalified_on" id="prequalified_on" class="input"
                                           value="{{ $vendor->prequalified_on?->format('Y-m-d') }}">
                                </div>
                                <div>
                                    <label for="prequalification_expires_on" class="label">Expires</label>
                                    <input type="date" name="prequalification_expires_on"
                                           id="prequalification_expires_on" class="input"
                                           value="{{ $vendor->prequalification_expires_on?->format('Y-m-d') }}">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm">Record it</button>
                        </form>
                    </div>
                @endif

                @if ($canEvaluate)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('vendors.evaluate', $vendor) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm font-medium text-[var(--text-strong)]">Record an assessment</p>
                            <p class="text-xs text-[var(--text-muted)]">
                                Folded into the running mean, not replacing it — one poor delivery
                                should move a long-standing supplier's score, not erase it.
                            </p>
                            <div>
                                <label for="score" class="label">Score out of 5</label>
                                <input type="number" name="score" id="score" class="input"
                                       step="0.1" min="0" max="5" required>
                            </div>
                            <div>
                                <label for="note" class="label">What happened</label>
                                <textarea name="note" id="note" rows="2" class="input"
                                          placeholder="Delivered three weeks late against PO-0114; quality acceptable."></textarea>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm">Record the assessment</button>
                        </form>
                    </div>
                @endif

                <x-register.transitions :action="route('vendors.transition', $vendor)"
                                        :transitions="$transitions" noun="vendor"/>
            </div>

            @if ($canBlacklist && ! $vendor->is_blacklisted)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Blacklist</h3>
                    </div>
                    <div class="p-5">
                        <form method="POST" action="{{ route('vendors.blacklist', $vendor) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm text-[var(--text-muted)]">
                                Bars this company from new work and moves it to Blacklisted. The reason
                                is permanent and is what the register shows anybody who asks why.
                            </p>
                            <div>
                                <label for="blacklist_reason" class="label">Reason</label>
                                <textarea name="reason" id="blacklist_reason" rows="3" class="input" required
                                          placeholder="Repeated supply of uncertified material against PO-0231 and PO-0244; certificates found to be falsified."></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger btn-sm">Blacklist this vendor</button>
                        </form>
                    </div>
                </div>
            @endif

            @if ($canLiftBlacklist && $vendor->is_blacklisted)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Lift the blacklisting</h3>
                    </div>
                    <div class="p-5">
                        <form method="POST" action="{{ route('vendors.blacklist.lift', $vendor) }}" class="space-y-3">
                            @csrf
                            @method('DELETE')
                            <p class="text-sm text-[var(--text-muted)]">
                                Returns the company to the register for review — not to approved. The
                                original reason stays on the timeline, and so will this one.
                            </p>
                            <div>
                                <label for="lift_reason" class="label">Reason</label>
                                <textarea name="reason" id="lift_reason" rows="3" class="input" required
                                          placeholder="Independent audit completed; corrective actions verified on site 12 March."></textarea>
                            </div>
                            <button type="submit" class="btn btn-secondary btn-sm">Lift it</button>
                        </form>
                    </div>
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this vendor was added."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Purchase orders and contracts already raised against this vendor keep their
                            record of it. If the company merely stopped trading with you, mark it
                            inactive instead — deleting removes it from every picker and from the
                            register, and a supplier you may need to answer questions about is worth
                            keeping.
                        </p>
                        <x-delete-form :action="route('vendors.destroy', $vendor)"
                                       label="Delete this vendor"
                                       :confirm="'Delete '.$vendor->name.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
