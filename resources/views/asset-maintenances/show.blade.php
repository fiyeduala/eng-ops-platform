{{--
    One job card: what is being done, by whom, and — once it is signed off — what it cost.

    SIGNING OFF IS A FORM, NOT A STATUS MOVE. Certifying that a service happened is the statement
    "this machine is safe to run again", and it is read afterwards by an insurer, an inspector and
    occasionally by an accident investigation. So it collects the costs, the downtime, the odometer
    and the findings in one act, behind `asset_maintenance.complete`, and the status dropdown refuses
    a direct move to completed and points here instead (§84).

    STARTING IS ALSO A FORM, for a smaller reason: the odometer at the moment work began is the only
    time anybody is standing next to the machine looking at the clock.

    Expects: $maintenance, $timeline, $transitions, $canEdit, $canDelete, $canComplete, $vendors
--}}
<x-layouts.app :title="$maintenance->reference">

    <x-page-header :title="$maintenance->title" :description="$maintenance->reference">
        <x-slot:actions>
            @if ($maintenance->status)
                <x-badge :tone="$maintenance->status->tone()">{{ $maintenance->status->label }}</x-badge>
            @endif

            @if ($maintenance->asset)
                <a href="{{ route('assets.show', $maintenance->asset) }}" class="btn btn-ghost btn-sm">
                    <x-icon name="package" class="h-3.5 w-3.5"/> {{ $maintenance->asset->code }}
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('asset-maintenances.edit', $maintenance) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($maintenance->isOverdue())
        <div class="card mb-5 border-rose-300 bg-rose-50 p-4 text-sm text-rose-900
                    dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            This job was due on {{ $maintenance->due_on?->format('j M Y') }} and has not been closed.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            @if ($maintenance->description)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Scope of work</h3></div>
                    <div class="whitespace-pre-line p-5 text-sm text-[var(--text-body)]">{{ $maintenance->description }}</div>
                </div>
            @endif

            @if ($maintenance->completed_at)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">What was done</h3>
                        <span class="text-xs text-[var(--text-muted)]">
                            Signed off {{ $maintenance->completed_at->format('j M Y') }}
                        </span>
                    </div>

                    <dl class="grid gap-4 p-5 sm:grid-cols-4">
                        <x-register.fact label="Labour"
                                         :value="$maintenance->labour_cost !== null ? number_format((float) $maintenance->labour_cost, 2) : null" mono/>
                        <x-register.fact label="Parts"
                                         :value="$maintenance->parts_cost !== null ? number_format((float) $maintenance->parts_cost, 2) : null" mono/>
                        <x-register.fact label="Total"
                                         :value="$maintenance->totalCost() !== null ? number_format($maintenance->totalCost(), 2) : null" mono/>
                        <x-register.fact label="Downtime"
                                         :value="$maintenance->downtime_hours !== null ? number_format((float) $maintenance->downtime_hours, 1).' h' : null"/>
                    </dl>

                    @if ($maintenance->findings)
                        <div class="border-t border-[var(--surface-border)] p-5">
                            <p class="mb-1 text-xs uppercase tracking-wide text-[var(--text-muted)]">Findings</p>
                            <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $maintenance->findings }}</p>
                        </div>
                    @endif
                </div>
            @endif

            @if ($canComplete && $maintenance->isInProgress())
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Certify complete</h3></div>

                    <form method="POST" action="{{ route('asset-maintenances.complete', $maintenance) }}"
                          class="space-y-5 p-5">
                        @csrf

                        <p class="text-sm text-[var(--text-muted)]">
                            Every field is optional — a job genuinely can be finished with nothing to
                            record but the fact that it happened. What is entered here cannot be
                            edited afterwards through the ordinary form.
                        </p>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-field label="Labour cost" name="labour_cost">
                                <x-input type="number" name="labour_cost" step="0.01" min="0"
                                         :value="old('labour_cost')"/>
                            </x-field>

                            <x-field label="Parts cost" name="parts_cost">
                                <x-input type="number" name="parts_cost" step="0.01" min="0"
                                         :value="old('parts_cost')"/>
                            </x-field>

                            <x-field label="Downtime (hours)" name="downtime_hours"
                                     help="How long the machine was unavailable, not how long the work took.">
                                <x-input type="number" name="downtime_hours" step="0.1" min="0"
                                         :value="old('downtime_hours')"/>
                            </x-field>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Odometer / hours at completion" name="odometer"
                                     help="Pushes the machine's own reading forward. It is refused if it is lower than the reading already recorded.">
                                <x-input type="number" name="odometer" min="0" step="1" :value="old('odometer')"/>
                            </x-field>

                            <x-field label="Workshop" name="vendor_id"
                                     help="If the work went out and was not recorded when the card was raised.">
                                <x-select name="vendor_id" :options="$vendors"
                                          :selected="old('vendor_id', $maintenance->vendor_id)"
                                          placeholder="Not stated"/>
                            </x-field>
                        </div>

                        <x-field label="Findings" name="findings"
                                 help="What was actually wrong, what was replaced, what to watch next time. This is the field somebody reads in two years.">
                            <x-textarea name="findings" :value="old('findings')" rows="4"/>
                        </x-field>

                        <button type="submit" class="btn btn-primary">
                            Certify the job complete
                        </button>
                        <p class="help">
                            The machine is returned to service and the job card is closed.
                        </p>
                    </form>
                </div>
            @endif

            <x-timeline :entries="$timeline" empty="Nothing has happened to this job card yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Particulars</h3></div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="Reference" :value="$maintenance->reference" mono/>
                    <x-register.fact label="Asset">
                        @if ($maintenance->asset)
                            <a href="{{ route('assets.show', $maintenance->asset) }}" class="hover:text-brand-600">
                                {{ $maintenance->asset->displayName() }}
                            </a>
                        @else
                            —
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Kind"
                                     :value="$maintenance->is_planned ? 'Planned work' : 'Unplanned — breakdown'"/>
                    <x-register.fact label="Type of work" :value="$maintenance->maintenance_type"/>
                    <x-register.fact label="Due on" :value="$maintenance->due_on?->format('j M Y')"/>
                    <x-register.fact label="Due at odometer"
                                     :value="$maintenance->due_odometer ? number_format((float) $maintenance->due_odometer) : null" mono/>
                    <x-register.fact label="Started" :value="$maintenance->started_at?->format('j M Y')"/>
                    <x-register.fact label="Our person" :value="$maintenance->performer?->name"/>
                    <x-register.fact label="Workshop" :value="$maintenance->vendor?->name"/>
                    <x-register.fact label="Work order" :value="$maintenance->work_order_reference" mono/>
                    <x-register.fact label="Raised by" :value="$maintenance->creator?->name"/>
                </dl>
            </div>

            @php
                // Work can start from anywhere that is not already running and not closed — a job
                // that was put on hold and then picked up again is ordinary, and the service accepts
                // it. Gating this on `scheduled` alone would strand those cards with no way forward
                // but a status move that re-routes back here anyway.
                $canStart = $canEdit
                    && ! $maintenance->isClosed()
                    && $maintenance->statusKey() !== 'in_progress';
            @endphp

            @if (count($transitions) || $canDelete || $canStart)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Job status</h3></div>
                    <div class="space-y-4 p-5">
                        @if ($canStart)
                            {{-- Its own form because of one field: the reading on the clock at the
                                 moment somebody was standing next to the machine. --}}
                            <form method="POST" action="{{ route('asset-maintenances.start', $maintenance) }}"
                                  class="space-y-3">
                                @csrf

                                <x-field label="Odometer / hours now" name="odometer"
                                         help="Optional. Recorded against the machine as well as this card.">
                                    <x-input type="number" name="odometer" min="0" step="1"/>
                                </x-field>

                                <button type="submit" class="btn btn-secondary btn-sm w-full">
                                    Start work
                                </button>
                            </form>
                        @endif

                        @if (count($transitions))
                            <x-register.transitions :action="route('asset-maintenances.transition', $maintenance)"
                                                    :transitions="$transitions" noun="job card"/>
                        @endif

                        @if ($canDelete)
                            <x-delete-form :action="route('asset-maintenances.destroy', $maintenance)"
                                           label="Delete this job card"
                                           confirm="Deleting removes the job card and any costs recorded on it. Continue?"/>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
