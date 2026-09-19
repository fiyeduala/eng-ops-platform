<x-layouts.app :title="$case->number">
    <x-page-header :title="$case->number.' — '.($case->employee?->fullName() ?? 'unknown')"
                   :description="ucfirst(str_replace('_', ' ', (string) $case->category)).' · '.ucfirst((string) $case->severity)">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('disciplinary-cases.edit', $case) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h2 class="card-title">The allegation</h2>
                <p class="mt-2 whitespace-pre-line text-sm">{{ $case->summary }}</p>
            </div>

            {{--
                Each step is its own form posting to its own route with its own policy check. They
                appear in the order the process runs, and a step nobody has permission for simply
                is not here — there is no greyed-out button standing in for an authorization (§6).
            --}}
            @if ($canInvestigate || $case->investigation_notes)
                <div class="card p-5">
                    <h2 class="card-title">Investigation</h2>
                    @if ($canInvestigate)
                        <form method="POST" action="{{ route('disciplinary-cases.investigation', $case) }}" class="mt-3 space-y-2">@csrf
                            <x-textarea name="investigation_notes" rows="8"
                                        placeholder="What was found: who was spoken to, what was seen, what the records show.">{{ old('investigation_notes', $case->investigation_notes) }}</x-textarea>
                            <button class="btn btn-secondary btn-sm">Save investigation</button>
                        </form>
                    @else
                        <p class="mt-2 whitespace-pre-line text-sm">{{ $case->investigation_notes }}</p>
                    @endif
                </div>
            @endif

            @if ($canScheduleHearing || $case->hearing_on || $case->hearing_notes)
                <div class="card p-5">
                    <h2 class="card-title">Hearing</h2>
                    @if ($case->hearing_on)
                        <p class="mt-1 text-sm">Scheduled for {{ $case->hearing_on->format('j M Y') }}.</p>
                    @endif

                    @if ($canScheduleHearing)
                        <form method="POST" action="{{ route('disciplinary-cases.hearing', $case) }}" class="mt-3 flex flex-wrap items-end gap-3">@csrf
                            <div>
                                <label class="label" for="hearing_on">{{ $case->hearing_on ? 'Move the hearing to' : 'Hearing date' }}</label>
                                <x-input type="date" name="hearing_on" :value="old('hearing_on', $case->hearing_on?->toDateString())"/>
                            </div>
                            <button class="btn btn-secondary btn-sm">{{ $case->hearing_on ? 'Reschedule' : 'Schedule hearing' }}</button>
                        </form>
                    @endif

                    @if ($canInvestigate)
                        <form method="POST" action="{{ route('disciplinary-cases.hearing-notes', $case) }}" class="mt-4 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                            <label class="label" for="hearing_notes">What was said at the hearing</label>
                            <x-textarea name="hearing_notes" rows="8"
                                        placeholder="Who attended, what the person said in answer, what was put to them.">{{ old('hearing_notes', $case->hearing_notes) }}</x-textarea>
                            <button class="btn btn-secondary btn-sm">Save hearing record</button>
                        </form>
                    @elseif ($case->hearing_notes)
                        <p class="mt-3 whitespace-pre-line text-sm">{{ $case->hearing_notes }}</p>
                    @endif
                </div>
            @endif

            {{-- THE DECISION. Separate permission from investigating on purpose: whether the same
                 person may do both is the company's rule, expressed in who holds `disciplinary.decide`. --}}
            @if ($canDecide)
                <div class="card p-5">
                    <h2 class="card-title">{{ $case->isDecided() ? 'Revise the outcome' : 'Decide the outcome' }}</h2>
                    <form method="POST" action="{{ route('disciplinary-cases.decide', $case) }}" class="mt-3 space-y-4">@csrf
                        <div class="grid gap-4 md:grid-cols-2">
                            <x-field label="Outcome" name="outcome" required>
                                <x-select name="outcome" :options="$outcomes" :selected="old('outcome', $case->outcome)" placeholder="Choose an outcome"/>
                            </x-field>
                            <x-field label="Sanction lapses on" name="sanction_expires_on"
                                     help="When a warning stops counting. Leave empty for an outcome that does not lapse.">
                                <x-input type="date" name="sanction_expires_on" :value="old('sanction_expires_on', $case->sanction_expires_on?->toDateString())"/>
                            </x-field>
                        </div>
                        <x-field label="Reasons" name="outcome_notes" help="Why this outcome. The person is entitled to know, and an appeal is heard against it.">
                            <x-textarea name="outcome_notes" rows="5">{{ old('outcome_notes', $case->outcome_notes) }}</x-textarea>
                        </x-field>
                        <button class="btn btn-primary btn-sm">Record the outcome</button>
                    </form>
                </div>
            @elseif ($case->isDecided())
                <div class="card p-5">
                    <h2 class="card-title">Outcome</h2>
                    <p class="mt-2 text-sm">
                        <span class="font-medium text-[var(--text-strong)]">{{ $case->outcomeLabel() }}</span>
                        on {{ $case->decided_on?->format('j M Y') }}@if ($case->decidedBy) by {{ $case->decidedBy->name }}@endif.
                    </p>
                    @if ($case->outcome_notes)<p class="mt-2 whitespace-pre-line text-sm">{{ $case->outcome_notes }}</p>@endif
                </div>
            @endif

            @if ($canDecideAppeal || $case->appeal_received_on)
                <div class="card p-5">
                    <h2 class="card-title">Appeal</h2>
                    @if ($case->appeal_received_on)
                        <p class="mt-1 text-sm">Received {{ $case->appeal_received_on->format('j M Y') }}.</p>
                    @endif
                    @if ($case->appeal_outcome)
                        <p class="mt-1 text-sm font-medium text-[var(--text-strong)]">{{ ucfirst(str_replace('_', ' ', $case->appeal_outcome)) }}</p>
                    @endif
                    @if ($case->appeal_notes)<p class="mt-2 whitespace-pre-line text-sm">{{ $case->appeal_notes }}</p>@endif

                    @if ($canDecideAppeal && ! $case->appeal_received_on)
                        <form method="POST" action="{{ route('disciplinary-cases.appeal', $case) }}" class="mt-3 flex flex-wrap items-end gap-3">@csrf
                            <div>
                                <label class="label" for="appeal_received_on">Appeal received on</label>
                                <x-input type="date" name="appeal_received_on" :value="old('appeal_received_on')"/>
                            </div>
                            <button class="btn btn-secondary btn-sm">Record the appeal</button>
                        </form>
                    @endif

                    @if ($canDecideAppeal && $case->appeal_received_on)
                        {{-- An appeal that succeeds rewrites the sanction here rather than leaving a
                             contradicted one on the record: the outcome field below is optional and
                             changes the original finding when it is filled in. --}}
                        <form method="POST" action="{{ route('disciplinary-cases.appeal-decision', $case) }}" class="mt-4 space-y-4 border-t border-[var(--border)] pt-4">@csrf
                            <div class="grid gap-4 md:grid-cols-3">
                                <x-field label="Appeal decision" name="appeal_outcome" required>
                                    <x-select name="appeal_outcome"
                                              :options="['upheld' => 'Appeal upheld', 'partly_upheld' => 'Partly upheld', 'dismissed' => 'Appeal dismissed']"
                                              :selected="old('appeal_outcome', $case->appeal_outcome)" placeholder="Choose"/>
                                </x-field>
                                <x-field label="Replace the outcome with" name="outcome" help="Only if the appeal changes the finding.">
                                    <x-select name="outcome" :options="$outcomes" :selected="old('outcome')" placeholder="Leave the outcome as it is"/>
                                </x-field>
                                <x-field label="Sanction lapses on" name="sanction_expires_on">
                                    <x-input type="date" name="sanction_expires_on" :value="old('sanction_expires_on', $case->sanction_expires_on?->toDateString())"/>
                                </x-field>
                            </div>
                            <x-field label="Reasons" name="appeal_notes">
                                <x-textarea name="appeal_notes" rows="4">{{ old('appeal_notes') }}</x-textarea>
                            </x-field>
                            <button class="btn btn-primary btn-sm">Record the appeal decision</button>
                        </form>
                    @endif
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this case."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$case->status?->label"/>
                    <x-register.fact label="Person" :value="$case->employee?->fullName()"/>
                    <x-register.fact label="Department" :value="$case->employee?->orgUnit?->name"/>
                    <x-register.fact label="Category" :value="ucfirst(str_replace('_', ' ', (string) $case->category))"/>
                    <x-register.fact label="Severity" :value="ucfirst((string) $case->severity)"/>
                    <x-register.fact label="Incident on" :value="$case->incident_on?->format('j M Y')"/>
                    <x-register.fact label="Reported on" :value="$case->reported_on?->format('j M Y')"/>
                    <x-register.fact label="Reported by" :value="$case->reporter?->name"/>
                    <x-register.fact label="Investigator" :value="$case->investigator?->name"/>
                    <x-register.fact label="Outcome" :value="$case->outcomeLabel()"/>
                    @if ($case->isDecided())
                        <x-register.fact label="Sanction" :value="$case->sanctionIsLive() ? 'Live'.($case->sanction_expires_on ? ' until '.$case->sanction_expires_on->format('j M Y') : '') : 'No longer live'"/>
                    @endif
                    <x-register.fact label="Closed" :value="$case->closed_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Closed by" :value="$case->closedBy?->name"/>
                </dl>
            </div>

            @if ($canAssign)
                <form method="POST" action="{{ route('disciplinary-cases.investigator', $case) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">{{ $case->investigator ? 'Change the investigator' : 'Assign an investigator' }}</h2>
                    <p class="text-xs text-[var(--text-muted)]">Assigning somebody gives them access to this case and takes it from whoever it replaces.</p>
                    <x-select name="investigator_id" :options="$users" :selected="$case->investigator_id" placeholder="Choose a person"/>
                    <button class="btn btn-secondary btn-sm w-full">Assign</button>
                </form>
            @endif

            @if ($canClose && ! $case->isClosedCase())
                <form method="POST" action="{{ route('disciplinary-cases.close', $case) }}" class="card p-5">@csrf
                    <button class="btn btn-secondary btn-sm w-full"
                            onclick="return confirm('Close this case? The record stays, and a live sanction keeps running until it lapses.')">Close the case</button>
                </form>
            @endif

            {{-- Withdrawing is not deleting. An allegation that should not have been made is part of
                 the record of how the company behaved, and the person is entitled to have it say so. --}}
            @if ($canWithdraw)
                <form method="POST" action="{{ route('disciplinary-cases.withdraw', $case) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Withdraw</h2>
                    <p class="text-xs text-[var(--text-muted)]">For a case that should not have been opened. The record is kept and marked withdrawn.</p>
                    <x-textarea name="reason" rows="2" placeholder="Why is this being withdrawn?"></x-textarea>
                    <button class="btn btn-ghost btn-sm w-full">Withdraw the case</button>
                </form>
            @endif

            @if ($canDelete)
                <div class="card p-5">
                    <x-delete-form :action="route('disciplinary-cases.destroy', $case)" label="Delete case"
                                   confirm="Delete this case? Withdrawing it keeps the record and is usually the right act."/>
                </div>
            @endif
        </div>
    </div>

    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$case" class="card mt-5"/>

</x-layouts.app>
