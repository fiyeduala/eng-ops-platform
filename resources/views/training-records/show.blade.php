{{--
    One training record.

    THE EXPIRY IS THE HEADLINE. Lapsed, lapsing, or good — that is what the person opening this page
    came for, and it sits above everything else on the screen rather than in a sidebar.

    RENEWING CREATES A NEW RECORD AND LEAVES THIS ONE ALONE. The certificate somebody held in 2024
    was valid in 2024, and that is exactly the fact an investigation asks about two years later, so
    the renewal form on this page is a create form — not an edit of the dates in front of you (§59).

    THE CERTIFICATE LINK APPEARS ONLY IF THE VIEWER CAN OPEN THE DOCUMENT. Advertising a file this
    application will then refuse to hand over tells somebody it exists, which is the disclosure the
    refusal was there to prevent (§67, §68).

    Expects: $record, $timeline, $transitions, $workflowInstance, $canOpenCertificate, $canEdit,
             $canDelete, $canRenew, $courses, $results, $people, $projects, $units,
             $confidentialities, $certificates
--}}
<x-layouts.app :title="$record->number">

    <x-page-header :title="$record->number" :description="$record->displayName()">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('training-records.edit', $record) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
            <a href="{{ route('training-records.index') }}" class="btn btn-ghost btn-sm">All records</a>
        </x-slot:actions>
    </x-page-header>

    @error('renew') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($record->expires_on === null)
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm font-semibold text-[var(--text-strong)]">No expiry</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                This certificate does not lapse and will never appear on the expiry report.
            </p>
        </div>
    @elseif ($record->hasExpired())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm font-semibold text-rose-800 dark:text-rose-200">Lapsed</p>
            <p class="mt-1 text-sm text-rose-800 dark:text-rose-200">
                Ran out on {{ $record->expires_on->format('j M Y') }}.
                {{ $record->holderLabel() }} should not be relied on for this
                @if ($record->course) — {{ $record->course->name }} @endif until it is renewed.
            </p>
        </div>
    @elseif ($record->expiresWithin(60))
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Expiring soon</p>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                Runs out on {{ $record->expires_on->format('j M Y') }}. Book the refresher now rather
                than the week it lapses.
            </p>
        </div>
    @else
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/60 dark:bg-emerald-950/40">
            <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">Current</p>
            <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
                Valid until {{ $record->expires_on->format('j M Y') }}.
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
                    <h3 class="card-title">The certificate</h3>
                    <p class="card-description">
                        {{ $record->course?->name ?: 'No course recorded' }}
                    </p>
                </div>
                <div class="space-y-5 p-5">
                    <div class="grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Certificate number</p>
                            <p class="mt-1 font-mono text-[var(--text-body)]">{{ $record->certificate_number ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Result</p>
                            <p class="mt-1 text-[var(--text-body)]">
                                {{ $record->result ? Str::ucfirst($record->result) : '—' }}
                                @if ($record->score !== null)
                                    <span class="text-[var(--text-muted)]">({{ $record->score }})</span>
                                @endif
                            </p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Provider</p>
                            <p class="mt-1 text-[var(--text-body)]">{{ $record->provider ?: '—' }}</p>
                        </div>
                    </div>

                    {{-- Offered only when the viewer can actually open it. Naming a file somebody
                         will be refused tells them it exists (§67, §68). --}}
                    @if ($canOpenCertificate && $record->document)
                        <div class="border-t border-[var(--surface-border)] pt-4">
                            <a href="{{ route('documents.show', $record->document) }}"
                               class="inline-flex items-center gap-2 text-sm text-brand-600 hover:underline">
                                <x-icon name="file-text" class="h-4 w-4"/>
                                {{ $record->document->displayName() }}
                            </a>
                        </div>
                    @elseif ($record->document_id)
                        <p class="border-t border-[var(--surface-border)] pt-4 text-sm text-[var(--text-muted)]">
                            A scanned certificate is on file in document control.
                        </p>
                    @endif

                    @if ($record->notes)
                        <div class="border-t border-[var(--surface-border)] pt-4">
                            <p class="whitespace-pre-line text-sm leading-relaxed text-[var(--text-body)]">{{ $record->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- A CREATE FORM, NOT AN EDIT FORM. The holder and the course come through unchanged
                 from this record; everything typed here belongs to the new certificate, and this
                 one is left exactly as it stands. --}}
            @if ($canRenew)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Renew</h3>
                        <p class="card-description">
                            Records a new certificate for {{ $record->holderLabel() }}.
                            {{ $record->number }} is kept as it is.
                        </p>
                    </div>
                    <form method="POST" action="{{ route('training-records.renew', $record) }}"
                          class="space-y-5 p-5">
                        @csrf

                        {{-- Carried through so the new record is about the same person. The form
                             request still checks both against this tenant, and the service copies
                             the course and placement across regardless (§84). --}}
                        <input type="hidden" name="user_id" value="{{ $record->user_id }}">
                        <input type="hidden" name="person_name" value="{{ $record->person_name }}">

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Completed on" name="completed_on">
                                <x-input type="date" name="completed_on"
                                         :value="old('completed_on', now()->toDateString())"/>
                            </x-field>

                            <x-field label="Expires on" name="expires_on"
                                     help="Leave blank to take the course's validity.">
                                <x-input type="date" name="expires_on" :value="old('expires_on')"/>
                            </x-field>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-field label="Certificate number" name="certificate_number">
                                <x-input name="certificate_number" :value="old('certificate_number')"
                                         class="font-mono"/>
                            </x-field>

                            <x-field label="Result" name="result">
                                <x-select name="result" :options="$results" :selected="old('result')"
                                          placeholder="Not recorded"/>
                            </x-field>

                            <x-field label="Provider" name="provider">
                                <x-input name="provider" :value="old('provider', $record->provider)"/>
                            </x-field>
                        </div>

                        <x-field label="Who can read it" name="confidentiality" required>
                            <x-select name="confidentiality" :options="$confidentialities"
                                      :selected="old('confidentiality', $record->confidentiality?->value)"
                                      required/>
                        </x-field>

                        <button type="submit" class="btn btn-primary btn-sm">Record the renewal</button>
                    </form>
                </div>
            @endif

            <x-register.transitions :action="route('training-records.transition', $record)"
                                    :transitions="$transitions" noun="record"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this record yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$record->number" mono/>
                    <x-register.fact label="Status">
                        @if ($record->status)
                            <x-badge :tone="$record->status->tone()">{{ $record->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Recorded</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Who" :value="$record->holderLabel()"/>
                    <x-register.fact label="Company" :value="$record->company"/>
                    <x-register.fact label="Course" :value="$record->course?->displayName()"/>
                    <x-register.fact label="Completed" :value="$record->completed_on?->format('j M Y')"/>
                    <x-register.fact label="Expires"
                                     :value="$record->expires_on?->format('j M Y') ?: 'Never'"/>
                    <x-register.fact label="Trainer" :value="$record->trainer_name"/>
                    <x-register.fact label="Cost" :value="$record->cost !== null ? number_format((float) $record->cost, 2) : null"/>
                    <x-register.fact label="Project" :value="$record->project?->displayName()"/>
                    <x-register.fact label="Department" :value="$record->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$record->confidentiality?->label()"/>
                    <x-register.fact label="Recorded by" :value="$record->creator?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            A certificate on file is evidence that somebody was competent on a date.
                            Delete only a record entered in error — a superseded one is history, not
                            clutter.
                        </p>
                        <x-delete-form :action="route('training-records.destroy', $record)"
                                       label="Delete this record"
                                       confirm="Delete this training record?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
