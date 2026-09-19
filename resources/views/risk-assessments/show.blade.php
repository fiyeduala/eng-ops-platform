{{--
    One risk assessment.

    THE VALIDITY DATE IS THE FIRST THING ON THE PAGE when it has passed, above the title and above
    the hazards, because an assessment that lapsed in March is not a document with a small
    administrative problem — it is the reason work carried on with nothing behind it. Every other
    register in this application leads with status; this one leads with whether it is still true.

    THREE SIGNATURES, THREE NAMES. Assessed, reviewed and approved are shown as three separate rows
    even when two of them are empty, so that a half-signed assessment looks half-signed rather than
    looking finished with some fields the reader did not notice. The service refuses to let one
    person fill two of them; this page only reports what it did.

    THE HAZARD TABLE IS READ-ONLY HERE. Editing happens on `risk-assessments.hazards.edit`, which
    replaces the whole table in one PUT — so the two screens are deliberately not the same screen.
    Somebody reading an approved assessment should not be one stray keystroke from rewriting it.

    Expects: $assessment, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete,
             $canReview, $canApprove
--}}
<x-layouts.app :title="$assessment->displayName()">

    <x-page-header :title="$assessment->title" :description="$assessment->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('risk-assessments.hazards.edit', $assessment) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="shield" class="h-3.5 w-3.5"/> Hazards
                </a>
                <a href="{{ route('risk-assessments.edit', $assessment) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            <a href="{{ route('risk-assessments.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('review') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('approve') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($assessment->hasExpired())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm font-medium text-rose-800 dark:text-rose-200">
                This assessment lapsed on {{ $assessment->valid_to->format('j M Y') }}.
            </p>
            <p class="mt-1 text-sm text-rose-700 dark:text-rose-300">
                It is no longer the basis for the work it covers. Review it and reissue it before
                the activity continues.
            </p>
        </div>
    @elseif ($assessment->expiresWithin(30))
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                Valid until {{ $assessment->valid_to->format('j M Y') }} — due for review within the month.
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
                    <h3 class="card-title">What this covers</h3>
                    @if ($assessment->assessment_type)
                        <x-badge tone="neutral">{{ $assessment->assessment_type }}</x-badge>
                    @endif
                </div>
                <div class="space-y-5 p-5">
                    @if ($assessment->activity)
                        <x-register.fact label="Activity" :value="$assessment->activity"/>
                    @endif

                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                        {{ $assessment->description ?: 'No scope was written beyond the title.' }}
                    </p>

                    @if ($assessment->location)
                        <x-register.fact label="Where" :value="$assessment->location"/>
                    @endif
                </div>
            </div>

            {{-- The matrix. Read-only: this page reports the assessment, the hazards screen writes
                 it. Both columns of scores are shown side by side because the whole argument of a
                 risk assessment is the gap between them — a residual score that never moved is a
                 hazard whose controls exist on paper only, and it has to be visible to the person
                 signing rather than something they would have to work out. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Hazards and controls</h3>
                    <div class="flex items-center gap-2">
                        @php $worst = $assessment->highestResidualScore(); @endphp
                        @if ($worst !== null)
                            @php $band = $assessment->highestResidualBand(); @endphp
                            <x-badge :tone="match ($band) { 'high' => 'danger', 'medium' => 'warning', default => 'neutral' }">
                                Worst residual: {{ ucfirst($band) }} ({{ $worst }})
                            </x-badge>
                        @endif
                        @if ($canEdit)
                            <a href="{{ route('risk-assessments.hazards.edit', $assessment) }}"
                               class="btn btn-ghost btn-sm">Edit the table</a>
                        @endif
                    </div>
                </div>

                @if ($assessment->hazards->isEmpty())
                    <div class="p-5">
                        <x-empty-state icon="shield" title="No hazards recorded yet"
                                       description="An assessment with an empty table has not been done — it has been created. Write the hazards before asking anyone to sign it."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                <tr>
                                    <th class="px-4 py-3">Hazard</th>
                                    <th class="px-4 py-3">Who is at risk</th>
                                    <th class="px-4 py-3">Controls in place</th>
                                    <th class="px-4 py-3 text-center">Raw</th>
                                    <th class="px-4 py-3">Further controls</th>
                                    <th class="px-4 py-3 text-center">Residual</th>
                                    <th class="px-4 py-3">Owner</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--surface-border)]">
                                @foreach ($assessment->hazards as $hazard)
                                    <tr class="align-top">
                                        <td class="px-4 py-3 font-medium text-[var(--text-strong)]">
                                            {{ $hazard->hazard }}
                                        </td>
                                        <td class="px-4 py-3 text-[var(--text-body)]">
                                            {{ $hazard->who_is_at_risk ?: '—' }}
                                        </td>
                                        <td class="whitespace-pre-line px-4 py-3 text-[var(--text-body)]">
                                            {{ $hazard->existing_controls ?: '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if ($hazard->risk_score === null)
                                                <span class="text-[var(--text-muted)]">—</span>
                                            @else
                                                <span class="font-mono text-[var(--text-body)]">{{ $hazard->risk_score }}</span>
                                                <div class="text-xs text-[var(--text-muted)]">
                                                    {{ $hazard->likelihood }}&times;{{ $hazard->severity }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="whitespace-pre-line px-4 py-3 text-[var(--text-body)]">
                                            {{ $hazard->additional_controls ?: '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if ($hazard->residual_score === null)
                                                <span class="text-[var(--text-muted)]">—</span>
                                            @else
                                                <x-badge :tone="match ($hazard->band()) { 'high' => 'danger', 'medium' => 'warning', default => 'success' }">
                                                    {{ $hazard->residual_score }}
                                                </x-badge>
                                                @unless ($hazard->wasReducedByControls())
                                                    {{-- Flagged, never hidden. Sometimes it is the
                                                         honest answer; sometimes nobody filled the
                                                         second pair in. The reader decides which. --}}
                                                    <div class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                                        not reduced
                                                    </div>
                                                @endunless
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-[var(--text-body)]">
                                            {{ $hazard->responsible?->name ?: '—' }}
                                            @if ($hazard->target_date)
                                                <div class="text-xs text-[var(--text-muted)]">
                                                    by {{ $hazard->target_date->format('j M Y') }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- THREE SIGNATURES, THREE PEOPLE. The service refuses an approval by the person who
                 wrote the assessment and refuses to approve an empty table at all; both refusals
                 come back as errors above rather than as a disabled button, because a button that
                 is merely greyed out teaches nobody why (§6, §84). --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Signatures</h3>
                    @if ($assessment->isApproved())
                        <x-badge tone="success">Approved</x-badge>
                    @endif
                </div>
                <div class="divide-y divide-[var(--surface-border)]">
                    <div class="flex items-baseline justify-between gap-4 px-5 py-4">
                        <div>
                            <p class="text-sm font-medium text-[var(--text-strong)]">Assessed</p>
                            <p class="text-sm text-[var(--text-muted)]">
                                {{ $assessment->assessor?->name ?: 'Nobody named' }}
                            </p>
                        </div>
                        <p class="text-sm text-[var(--text-muted)]">
                            {{ $assessment->assessed_on?->format('j M Y') ?: '—' }}
                        </p>
                    </div>

                    <div class="flex items-baseline justify-between gap-4 px-5 py-4">
                        <div>
                            <p class="text-sm font-medium text-[var(--text-strong)]">Reviewed</p>
                            <p class="text-sm text-[var(--text-muted)]">
                                {{ $assessment->reviewer?->name ?: 'Not yet reviewed' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <p class="text-sm text-[var(--text-muted)]">
                                {{ $assessment->reviewed_at?->format('j M Y') ?: '—' }}
                            </p>
                            @if ($canReview && ! $assessment->isApproved())
                                <form method="POST" action="{{ route('risk-assessments.review', $assessment) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary btn-sm">Record my review</button>
                                </form>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-baseline justify-between gap-4 px-5 py-4">
                        <div>
                            <p class="text-sm font-medium text-[var(--text-strong)]">Approved</p>
                            <p class="text-sm text-[var(--text-muted)]">
                                {{ $assessment->approver?->name ?: 'Not yet approved' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <p class="text-sm text-[var(--text-muted)]">
                                {{ $assessment->approved_at?->format('j M Y') ?: '—' }}
                            </p>
                            @if ($canApprove && ! $assessment->isApproved())
                                <form method="POST" action="{{ route('risk-assessments.approve', $assessment) }}"
                                      x-data="{ armed: false }"
                                      @submit="if (!armed) { $event.preventDefault(); armed = true; }">
                                    @csrf
                                    <button type="submit" class="btn btn-sm" :class="armed ? 'btn-danger' : 'btn-primary'">
                                        <span x-text="armed ? 'Accept the residual risk?' : 'Approve'">Approve</span>
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <x-register.transitions :action="route('risk-assessments.transition', $assessment)"
                                    :transitions="$transitions" noun="assessment"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this assessment yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                    <x-badge :tone="$assessment->status?->tone() ?? 'neutral'">
                        {{ $assessment->status?->label ?? 'No status' }}
                    </x-badge>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$assessment->number" mono/>
                    <x-register.fact label="Type" :value="$assessment->assessment_type ?: 'Not set'"/>
                    <x-register.fact label="Project" :value="$assessment->project?->name ?: 'Not on a project'"/>
                    <x-register.fact label="Discipline" :value="$assessment->discipline?->name ?: 'Not set'"/>
                    <x-register.fact label="Department" :value="$assessment->orgUnit?->name ?: 'Not set'"/>
                    <x-register.fact label="Confidentiality"
                                     :value="$assessment->confidentiality?->label()"/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Validity</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Valid from"
                                     :value="$assessment->valid_from?->format('j M Y') ?: 'Not set'"/>
                    <x-register.fact label="Valid to"
                                     :value="$assessment->valid_to?->format('j M Y') ?: 'No expiry recorded'"/>
                    <x-register.fact label="Review every"
                                     :value="$assessment->review_frequency_months
                                         ? $assessment->review_frequency_months.' months'
                                         : 'Not set'"/>
                    <x-register.fact label="Raised by"
                                     :value="$assessment->creator?->name ?: 'Unknown'"/>
                    <x-register.fact label="Raised on"
                                     :value="$assessment->created_at?->format('j M Y') ?: '—'"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            The hazards go with it. An assessment that has been worked to should be
                            superseded rather than deleted — the history is the point.
                        </p>
                        <x-delete-form :action="route('risk-assessments.destroy', $assessment)"
                                       label="Delete this assessment"
                                       confirm="Delete it and its hazards?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$assessment" class="card mt-5"/>

</x-layouts.app>
