{{--
    One non-conformance.

    FIVE ACTS, FIVE FORMS, AND THEY BELONG TO DIFFERENT PEOPLE. Issue sends the finding to the party
    responsible; respond is that party's account of it; disposition is the engineering decision about
    what happens to the work; verify confirms the decision was actually carried out; close files it.
    Each is its own form behind its own permission, in that order down the page, and no two of them
    are ever merged into one button — this is the register that moves money, and a single control
    doing two of these would put two signatures on one act.

    NOBODY DISPOSITIONS THEIR OWN. The service refuses it at any scope and the policy refuses it
    again. "Use as is" decided by the person who raised the finding is exactly the disposition
    somebody is asked to justify years later while holding a failed component.

    THE DISPOSITION IS NOT THE STATUS. `disposition` says what was decided about the work; the status
    says where the paperwork has got to. Both are shown, separately, because a company that wants
    "use as is" to route differently from "rework" configures that in the workflow (§32).

    Expects: $ncr, $timeline, $transitions, $workflowInstance, $dispositions,
             $dispositionsNeedingNotes, $canOpenInspection, $canEdit, $canDelete, $canIssue,
             $canRespond, $canDisposition, $canVerify, $canClose, $canRaiseAction, $actionTypes,
             $actionPriorities, $people
--}}
<x-layouts.app :title="$ncr->displayName()">

    <x-page-header :title="$ncr->number" :description="$ncr->title">
        @if ($canEdit)
            <x-slot:actions>
                <a href="{{ route('ncrs.edit', $ncr) }}" class="btn btn-ghost btn-sm">Edit</a>
            </x-slot:actions>
        @endif
    </x-page-header>

    @error('issue') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('response') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('disposition') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('verify') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('close') <p class="error mb-4">{{ $message }}</p> @enderror

    {{-- One banner, read backwards from the furthest point the record has reached, so it always
         names the next act rather than the last one. --}}
    @if ($ncr->isClosedOut())
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm text-[var(--text-body)]">
                Closed on {{ $ncr->closed_at?->format('j M Y') }}
                @if ($ncr->closer) by {{ $ncr->closer->name }} @endif.
            </p>
        </div>
    @elseif ($ncr->isVerified())
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/60 dark:bg-emerald-950/40">
            <p class="text-sm text-emerald-800 dark:text-emerald-200">
                Verified on {{ $ncr->verified_at?->format('j M Y') }}
                @if ($ncr->verifier) by {{ $ncr->verifier->name }} @endif.
                The disposition was carried out. This is waiting to be closed.
            </p>
        </div>
    @elseif ($ncr->isDispositioned())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                Dispositioned <strong>{{ $dispositions[$ncr->disposition] ?? Str::headline((string) $ncr->disposition) }}</strong>
                on {{ $ncr->dispositioned_at?->format('j M Y') }}
                @if ($ncr->dispositioner) by {{ $ncr->dispositioner->name }} @endif.
                Somebody still has to confirm it was actually done.
            </p>
        </div>
    @elseif ($ncr->hasResponse())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                Answered on {{ $ncr->responded_at?->format('j M Y') }}. Awaiting an engineering
                disposition — until there is one, nothing has been decided about the work.
            </p>
        </div>
    @elseif ($ncr->required_by && $ncr->required_by->isPast())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm text-rose-800 dark:text-rose-200">
                A response was required by {{ $ncr->required_by->format('j M Y') }} and none has been
                recorded.
            </p>
        </div>
    @else
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm text-[var(--text-body)]">
                Awaiting a response from {{ $ncr->party_name ?: 'the party responsible' }}.
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

            {{-- The requirement comes first and in the strongest type on the page. It is what turns
                 this from a complaint into something that can be dispositioned. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The finding</h3>
                </div>
                <div class="space-y-4 p-5">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Requirement breached</p>
                        <p class="mt-1 whitespace-pre-line text-sm font-medium text-[var(--text-strong)]">
                            {{ $ncr->requirement ?: 'No requirement was recorded — this NCR names nothing it can be argued against.' }}
                        </p>
                    </div>

                    @if ($ncr->description)
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">What was found</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $ncr->description }}</p>
                        </div>
                    @endif

                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Raised against" :value="$ncr->party_name"/>
                        <x-register.fact label="Where" :value="$ncr->location"/>
                        <x-register.fact label="Found by" :value="$ncr->detector?->name"/>
                        <x-register.fact label="Found on" :value="$ncr->detected_on?->format('j M Y')"/>

                        {{-- No currency symbol, and blank when nobody priced it (§47). --}}
                        <x-register.fact label="Cost impact"
                                         :value="$ncr->cost_impact !== null ? number_format((float) $ncr->cost_impact, 2) : null"
                                         mono/>

                        <x-register.fact label="From inspection">
                            @if ($ncr->qualityInspection && $canOpenInspection)
                                <a href="{{ route('quality-inspections.show', $ncr->qualityInspection) }}"
                                   class="font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                                    {{ $ncr->qualityInspection->number }}
                                </a>
                            @elseif ($ncr->qualityInspection)
                                {{-- Named but not linked. A link that leads to a 403 tells somebody
                                     the record exists, which is the disclosure §68 forbids. --}}
                                <span class="text-sm text-[var(--text-muted)]">
                                    An inspection you cannot open
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </x-register.fact>
                    </dl>
                </div>
            </div>

            @if ($ncr->hasResponse())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">The response</h3>
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ $ncr->responded_at?->format('j M Y H:i') }}
                        </span>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $ncr->response }}</p>
                    </div>
                </div>
            @endif

            @if ($ncr->isDispositioned())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">The disposition</h3>
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ $ncr->dispositioned_at?->format('j M Y H:i') }}
                        </span>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm font-medium text-[var(--text-strong)]">
                            {{ $dispositions[$ncr->disposition] ?? Str::headline((string) $ncr->disposition) }}
                            <span class="font-normal text-[var(--text-muted)]">
                                — decided by {{ $ncr->dispositioner?->name ?? 'somebody no longer recorded' }}
                            </span>
                        </p>
                        @if ($ncr->disposition_notes)
                            <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $ncr->disposition_notes }}</p>
                        @endif
                        @if ($ncr->isVerified() && $ncr->verification_notes)
                            <div class="border-t border-[var(--surface-border)] pt-3">
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Verification</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $ncr->verification_notes }}</p>
                            </div>
                        @endif
                        @if ($ncr->isClosedOut() && $ncr->closure_notes)
                            <div class="border-t border-[var(--surface-border)] pt-3">
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Closure</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $ncr->closure_notes }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- The five acts, in the order they happen, each behind its own permission and its own
                 endpoint. A form only appears when the record is actually at that point — the
                 service refuses the rest, and offering a form that will be refused is a lie about
                 what the page does. --}}
            @if (! $ncr->isClosedOut())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Acts on this NCR</h3>
                    </div>
                    <div class="divide-y divide-[var(--surface-border)]">

                        @if ($canIssue && ! $ncr->hasResponse() && ! $ncr->isDispositioned())
                            <form method="POST" action="{{ route('ncrs.issue', $ncr) }}"
                                  class="space-y-3 p-5" x-data="{ armed: false }"
                                  @submit="if (!armed) { $event.preventDefault(); armed = true; }">
                                @csrf
                                <p class="text-sm text-[var(--text-muted)]">
                                    Issuing sends the finding to
                                    {{ $ncr->party_name ?: 'the party responsible' }} and starts the
                                    clock on their response.
                                </p>
                                <button type="submit" class="btn btn-primary btn-sm"
                                        x-text="armed ? 'Confirm — issue it' : 'Issue the NCR'">
                                    Issue the NCR
                                </button>
                            </form>
                        @endif

                        @if ($canRespond && ! $ncr->hasResponse() && ! $ncr->isDispositioned())
                            <form method="POST" action="{{ route('ncrs.respond', $ncr) }}" class="space-y-4 p-5">
                                @csrf
                                <x-field label="The response" name="response" required
                                         help="The other side's account of the finding. Recorded even when it is disagreed with — it frequently changes the disposition.">
                                    <x-textarea name="response" rows="4" required>{{ old('response') }}</x-textarea>
                                </x-field>
                                <button type="submit" class="btn btn-secondary btn-sm">Record the response</button>
                            </form>
                        @endif

                        @if ($canDisposition && ! $ncr->isDispositioned())
                            {{-- Which dispositions demand an explanation is a property of the
                                 disposition, and the service is what enforces it. The hint below is
                                 that same list said out loud, so nobody meets the refusal blind. --}}
                            <form method="POST" action="{{ route('ncrs.disposition', $ncr) }}"
                                  class="space-y-4 p-5"
                                  x-data="{ chosen: '', needsNotes: @js(array_values($dispositionsNeedingNotes)) }">
                                @csrf
                                <p class="text-sm text-[var(--text-muted)]">
                                    What happens to the work. This is not the status — it is the
                                    engineering decision, and it cannot be made by the person who
                                    raised the finding.
                                </p>

                                <x-field label="Disposition" name="disposition" required>
                                    <select name="disposition" class="input" required x-model="chosen">
                                        <option value="">Choose</option>
                                        @foreach ($dispositions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </x-field>

                                <x-field label="Reasoning" name="disposition_notes">
                                    <x-textarea name="disposition_notes" rows="4">{{ old('disposition_notes') }}</x-textarea>
                                </x-field>

                                <p class="text-sm text-amber-600" x-show="needsNotes.includes(chosen)" x-cloak>
                                    This disposition keeps non-conforming work in the permanent works
                                    or reclassifies it. The reasoning is required, and saving is
                                    refused without it.
                                </p>

                                <button type="submit" class="btn btn-secondary btn-sm">Record the disposition</button>
                            </form>
                        @endif

                        @if ($canVerify && $ncr->isDispositioned() && ! $ncr->isVerified())
                            <form method="POST" action="{{ route('ncrs.verify', $ncr) }}" class="space-y-4 p-5">
                                @csrf
                                <p class="text-sm text-[var(--text-muted)]">
                                    Confirming the disposition was actually carried out — usually
                                    weeks later, and usually by somebody else.
                                </p>
                                <x-field label="What was checked" name="verification_notes">
                                    <x-textarea name="verification_notes" rows="3">{{ old('verification_notes') }}</x-textarea>
                                </x-field>
                                <button type="submit" class="btn btn-secondary btn-sm">Verify it was done</button>
                            </form>
                        @endif

                        @if ($canClose)
                            <form method="POST" action="{{ route('ncrs.close', $ncr) }}"
                                  class="space-y-4 p-5" x-data="{ armed: false }"
                                  @submit="if (!armed) { $event.preventDefault(); armed = true; }">
                                @csrf
                                @unless ($ncr->isVerified())
                                    <p class="text-sm text-amber-600">
                                        This has not been verified. Closing is refused until somebody
                                        has confirmed the disposition was carried out.
                                    </p>
                                @endunless
                                <x-field label="Closing notes" name="closure_notes">
                                    <x-textarea name="closure_notes" rows="3">{{ old('closure_notes') }}</x-textarea>
                                </x-field>
                                <button type="submit" class="btn btn-primary btn-sm"
                                        x-text="armed ? 'Confirm — close it' : 'Close the NCR'">
                                    Close the NCR
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            {{-- The same partial every other source uses. An NCR raises corrective actions exactly
                 the way an incident does, and a second version written here would have drifted
                 within a phase (§90). --}}
            @include('corrective-actions.partials.attached', [
                'record' => $ncr,
                'route' => route('ncrs.actions.store', $ncr),
            ])
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Number" :value="$ncr->number" mono/>
                        <x-register.fact label="Status">
                            @if ($ncr->status)
                                <x-badge :tone="$ncr->status->tone()">{{ $ncr->status->label }}</x-badge>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Severity"
                                         :value="$ncr->severity ? Str::headline($ncr->severity) : null"/>
                        <x-register.fact label="Category"
                                         :value="$ncr->category ? Str::headline($ncr->category) : null"/>
                        <x-register.fact label="Source"
                                         :value="$ncr->source ? Str::headline($ncr->source) : null"/>
                        <x-register.fact label="Project" :value="$ncr->project?->displayName()"/>
                        <x-register.fact label="Discipline" :value="$ncr->discipline?->name"/>
                        <x-register.fact label="Owning unit" :value="$ncr->orgUnit?->name"/>
                        <x-register.fact label="Confidentiality" :value="$ncr->confidentiality?->label()"/>
                        <x-register.fact label="Response required by"
                                         :value="$ncr->required_by?->format('j M Y')"/>
                        <x-register.fact label="Raised by" :value="$ncr->creator?->name"/>
                    </dl>
                </div>
            </div>

            {{-- Who signed what. Four acts, four names, four moments — kept as a block of its own
                 because "who decided this could be left as it is" is the question this whole
                 register exists to be able to answer years afterwards. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Who signed what</h3>
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Answered"
                                         :value="$ncr->responded_at?->format('j M Y H:i')"/>
                        <x-register.fact label="Dispositioned by"
                                         :value="$ncr->dispositioner?->name"/>
                        <x-register.fact label="Verified by" :value="$ncr->verifier?->name"/>
                        <x-register.fact label="Closed by" :value="$ncr->closer?->name"/>
                    </dl>
                </div>
            </div>

            <x-register.transitions :action="route('ncrs.transition', $ncr)"
                                    :transitions="$transitions" noun="NCR"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this NCR was raised."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            @if ($ncr->isDispositioned())
                                This NCR carries an engineering decision about work that is in the
                                permanent works. Deleting it removes the only record of who made that
                                decision and why.
                            @else
                                Deleting removes the finding and everything hanging off it. Corrective
                                actions already raised will lose the record they came from.
                            @endif
                        </p>
                        <x-delete-form :action="route('ncrs.destroy', $ncr)"
                                       label="Delete this NCR"
                                       :confirm="'Delete '.$ncr->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$ncr" class="card mt-5"/>

</x-layouts.app>
