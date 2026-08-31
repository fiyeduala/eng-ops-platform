{{--
    One deliverable.

    THE THREE DATES ARE SHOWN TOGETHER AND NEVER COLLAPSED. Promised, forecast, actual. A register
    that displayed only "due date" and quietly rewrote it whenever somebody re-forecast would make
    slippage disappear from the one screen that exists to expose it, and the argument about who moved
    the date would have no evidence on either side.

    ISSUING IS SOMEBODY ELSE'S ACT. Reporting progress rides on `deliverable.edit` — the producer's
    own authority — while issuing sits behind `deliverable.review`, because "I have finished it" and
    "it is fit to leave the building" are different statements and usually different people (§10).

    Expects: $deliverable, $timeline, $transitions, $typeLabel, $workflowInstance, $documents,
             $canEdit, $canDelete, $canReportProgress, $canAttach, $canIssue
--}}
<x-layouts.app :title="$deliverable->displayName()">

    <x-page-header
        :title="$deliverable->title"
        :description="$deliverable->number.($typeLabel ? ' · '.$typeLabel : '')">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('deliverables.edit', $deliverable) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canIssue && ! $deliverable->isIssued())
                <form method="POST" action="{{ route('deliverables.issue', $deliverable) }}" class="inline"
                      x-data="{ armed: false }">
                    @csrf
                    <button type="button" class="btn btn-primary btn-sm" x-show="! armed" @click="armed = true">
                        <x-icon name="check" class="h-3.5 w-3.5"/> Mark issued
                    </button>
                    <div x-show="armed" x-cloak class="inline-flex items-center gap-2">
                        <input type="date" name="actual_issue_on" class="input py-1 text-sm"
                               value="{{ now()->toDateString() }}">
                        <button type="submit" class="btn btn-primary btn-sm">Confirm</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="armed = false">Cancel</button>
                    </div>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- state banner --}}
    @php $slip = $deliverable->slippageDays(); @endphp

    @if ($deliverable->isIssued())
        <div class="card mb-5 flex items-start gap-3 border-l-4 border-l-emerald-400 p-4">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p class="text-sm text-[var(--text-body)]">
                Issued {{ $deliverable->actual_issue_on->format('j M Y') }}@if ($slip !== null && $slip !== 0),
                    {{ $slip > 0 ? $slip.' day'.($slip === 1 ? '' : 's').' after' : abs($slip).' day'.(abs($slip) === 1 ? '' : 's').' before' }}
                    the promised date@endif.
            </p>
        </div>
    @elseif ($deliverable->isOverdue())
        <div class="card mb-5 flex items-start gap-3 border-l-4 border-l-rose-400 p-4">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-rose-600"/>
            <p class="text-sm text-[var(--text-body)]">
                This was promised for {{ $deliverable->planned_issue_on->format('j M Y') }} and has not been issued.
                @if ($deliverable->forecast_issue_on)
                    The current forecast is {{ $deliverable->forecast_issue_on->format('j M Y') }}.
                @endif
            </p>
        </div>
    @endif

    @error('issue')
        <div class="card mb-5 border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ============================================================ main column --}}
        <div class="space-y-5 lg:col-span-2">

            {{-- ------------------------------------------------------ description --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Scope</h3>
                </div>

                <div class="p-5">
                    @if (filled($deliverable->description))
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $deliverable->description }}</p>
                    @else
                        <p class="text-sm text-[var(--text-muted)]">
                            No description was recorded. The title is all anybody picking this up has to go on.
                        </p>
                    @endif
                </div>

                @if ($canEdit)
                    <x-register.transitions :action="route('deliverables.transition', $deliverable)"
                                            :transitions="$transitions" noun="deliverable"/>
                @endif
            </div>

            {{-- ------------------------------------------------------ progress --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Progress</h3>
                </div>

                <div class="p-5">
                    <div class="flex items-center gap-3">
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                            <div class="h-full rounded-full bg-brand-500"
                                 style="width: {{ (int) $deliverable->progress_percent }}%"></div>
                        </div>
                        <span class="text-sm font-medium text-[var(--text-strong)]">
                            {{ (int) $deliverable->progress_percent }}%
                        </span>
                    </div>

                    <p class="mt-2 text-xs text-[var(--text-muted)]">
                        Weight {{ rtrim(rtrim((string) $deliverable->weight, '0'), '.') ?: '1' }} — this line's share
                        of the scope when progress is rolled up to the discipline.
                    </p>

                    @if ($canReportProgress && ! $deliverable->isIssued())
                        <form method="POST" action="{{ route('deliverables.progress', $deliverable) }}"
                              class="mt-5 space-y-4 border-t border-[var(--surface-border)] pt-5">
                            @csrf

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field label="Percent complete" name="progress_percent" required>
                                    <x-input type="number" name="progress_percent" min="0" max="100" required
                                             :value="(int) $deliverable->progress_percent"/>
                                </x-field>

                                <x-field label="Forecast issue date" name="forecast_issue_on"
                                         help="When you now expect it. The promised date is left alone.">
                                    <x-input type="date" name="forecast_issue_on"
                                             :value="$deliverable->forecast_issue_on?->toDateString()"/>
                                </x-field>
                            </div>

                            <x-field label="Note" name="note"
                                     help="Optional, and written to the timeline. Worth using when the forecast moves.">
                                <x-textarea name="note" rows="2"
                                            placeholder="e.g. Awaiting survey data before the detail can be finalised."/>
                            </x-field>

                            <button type="submit" class="btn btn-primary btn-sm">Record progress</button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- ------------------------------------------------------ the document --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The document that satisfies it</h3>
                </div>

                <div class="p-5">
                    @if ($deliverable->document)
                        <a href="{{ route('documents.show', $deliverable->document) }}"
                           class="flex items-start gap-3 rounded-md border border-[var(--surface-border)] p-3 hover:border-brand-400">
                            <x-icon name="file-text" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
                            <span>
                                <span class="block text-sm font-medium text-[var(--text-strong)]">
                                    {{ $deliverable->document->title }}
                                </span>
                                <span class="block font-mono text-xs text-[var(--text-muted)]">
                                    {{ $deliverable->document->number }}
                                </span>
                            </span>
                        </a>
                    @else
                        <p class="text-sm text-[var(--text-muted)]">
                            Nothing attached yet. A deliverable exists as a promise from the day it is
                            planned — the file arrives later, and frequently months later.
                        </p>
                    @endif

                    @if ($canAttach)
                        <form method="POST" action="{{ route('deliverables.document', $deliverable) }}"
                              class="mt-4 space-y-3 border-t border-[var(--surface-border)] pt-4">
                            @csrf

                            <x-field label="Link a document" name="document_id"
                                     help="Only documents you can already open are listed. Choosing nothing removes the link.">
                                <x-select name="document_id" :options="$documents"
                                          :selected="$deliverable->document_id"
                                          placeholder="— no document —"/>
                            </x-field>

                            <button type="submit" class="btn btn-secondary btn-sm">
                                {{ $deliverable->document ? 'Change the link' : 'Attach' }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <x-timeline :entries="$timeline"
                        empty="Nothing has happened to this deliverable since it was created."/>
        </div>

        {{-- ============================================================ side column --}}
        <div class="space-y-5">

            @if ($workflowInstance)
                <x-workflow-progress :instance="$workflowInstance"/>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <x-register.fact label="Number" :value="$deliverable->number" mono/>
                    <x-register.fact label="Type" :value="$typeLabel"/>
                    <x-register.fact label="Revision" :value="$deliverable->revision"/>

                    <x-register.fact label="Status">
                        @if ($deliverable->status)
                            <x-badge :tone="$deliverable->status->tone()">{{ $deliverable->status->label }}</x-badge>
                        @else
                            —
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Project">
                        @if ($deliverable->project)
                            <a href="{{ route('projects.show', $deliverable->project) }}" class="hover:text-brand-600">
                                {{ $deliverable->project->displayName() }}
                            </a>
                        @else
                            —
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Discipline" :value="$deliverable->discipline?->name"/>
                    <x-register.fact label="Responsible" :value="$deliverable->responsible?->name ?? 'Unassigned'"/>
                    <x-register.fact label="Confidentiality" :value="$deliverable->confidentiality?->label()"/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Dates</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <x-register.fact label="Planned start"
                                     :value="$deliverable->planned_start_on?->format('j M Y')"/>
                    <x-register.fact label="Promised for"
                                     :value="$deliverable->planned_issue_on?->format('j M Y')"/>
                    <x-register.fact label="Forecast"
                                     :value="$deliverable->forecast_issue_on?->format('j M Y')"/>
                    <x-register.fact label="Issued"
                                     :value="$deliverable->actual_issue_on?->format('j M Y') ?? 'Not yet'"/>

                    <x-register.fact label="Raised">
                        {{ $deliverable->created_at?->format('j M Y') ?? '—' }}
                        @if ($deliverable->creator)
                            <span class="block text-xs text-[var(--text-muted)]">by {{ $deliverable->creator->name }}</span>
                        @endif
                    </x-register.fact>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card border-red-200">
                    <div class="card-header">
                        <h3 class="card-title">Delete this deliverable</h3>
                    </div>

                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            For a line raised in error. An issued deliverable cannot be deleted at all —
                            it is a record of something the company said it delivered.
                        </p>

                        <x-delete-form :action="route('deliverables.destroy', $deliverable)"
                                       label="Delete this deliverable"
                                       confirm="Delete this deliverable? Its history is kept, but it leaves the register."/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
