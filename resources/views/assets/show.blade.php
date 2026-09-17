{{--
    One asset: who has it, who had it before, and what has been done to it.

    THE CUSTODY TRAIL IS THE MAIN COLUMN, not the particulars. A serial number is looked up once a
    year; "who signed for this and in what condition" is looked up the week something goes missing,
    and it is the reason this module exists rather than being a spreadsheet.

    HANDING OVER IS A FORM ON THIS PAGE AND NOT AN EDIT FIELD. The four custody columns are a cache
    of the newest trail row; this form writes the row and lets the cache follow. That is why the
    asset's own edit form has no custodian field at all (§84).

    Expects: $asset, $maintenances, $timeline, $transitions, $canEdit, $canDelete, $canTransfer,
             $canDispose, $canSeeMaintenance, $projects, $units, $people, $confidentialities,
             $locations, $conditions, $requiresAuthoriser
--}}
<x-layouts.app :title="$asset->name">

    <x-page-header :title="$asset->name" :description="$asset->code">
        <x-slot:actions>
            @if ($asset->status)
                <x-badge :tone="$asset->status->tone()">{{ $asset->status->label }}</x-badge>
            @endif

            @if ($asset->is_vehicle && $asset->vehicle)
                <a href="{{ route('vehicles.show', $asset->vehicle) }}" class="btn btn-ghost btn-sm">
                    <x-icon name="truck" class="h-3.5 w-3.5"/> Fleet record
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('assets.edit', $asset) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($asset->custodyRefusalReason() && $asset->statusKey() !== 'draft')
        <div class="card mb-5 border-amber-300 bg-amber-50 p-4 text-sm text-amber-900
                    dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            {{ $asset->custodyRefusalReason() }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            {{-- Where it is now, said in one line before anything else. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Custody</h3>
                    @unless ($asset->custodyMatchesTrail())
                        {{-- Should never appear. It is here because the four columns above are a
                             cache, and a cache that has silently diverged from its source is worth
                             saying out loud rather than discovering during an audit. --}}
                        <x-badge tone="danger">Does not match the trail</x-badge>
                    @endunless
                </div>

                <dl class="grid gap-4 p-5 sm:grid-cols-4">
                    <x-register.fact label="Held by" :value="$asset->custodian?->name ?: 'Nobody'"/>
                    <x-register.fact label="Project" :value="$asset->project?->name"/>
                    <x-register.fact label="Unit" :value="$asset->orgUnit?->name"/>
                    <x-register.fact label="Location" :value="$asset->location?->name"/>
                </dl>

                @if ($canTransfer && $asset->custodyRefusalReason() === null)
                    <div class="border-t border-[var(--surface-border)] p-5"
                         x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
                        <button type="button" class="btn btn-secondary btn-sm" x-show="! open" @click="open = true">
                            Hand over
                        </button>

                        <form method="POST" action="{{ route('assets.transfer', $asset) }}"
                              class="space-y-5" x-show="open" x-cloak>
                            @csrf

                            <p class="text-sm text-[var(--text-muted)]">
                                Every field below is optional and they may all be left blank — that
                                records the asset being handed back to nobody, which is a real event
                                and worth writing down.
                            </p>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field label="To person" name="custodian_id">
                                    <x-select name="custodian_id" :options="$people"
                                              :selected="old('custodian_id')" placeholder="Nobody"/>
                                </x-field>

                                <x-field label="To project" name="project_id">
                                    <x-select name="project_id" :options="$projects"
                                              :selected="old('project_id')" placeholder="None"/>
                                </x-field>

                                <x-field label="To unit" name="org_unit_id">
                                    <x-select name="org_unit_id" :options="$units"
                                              :selected="old('org_unit_id')" placeholder="None"/>
                                </x-field>

                                <x-field label="To location" name="location_id">
                                    <x-select name="location_id" :options="$locations"
                                              :selected="old('location_id')" placeholder="None"/>
                                </x-field>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field label="Condition at handover" name="condition"
                                         help="What it looked like as it changed hands. This is the field that settles an argument later.">
                                    <x-select name="condition" :options="$conditions"
                                              :selected="old('condition', 'good')" placeholder="Not stated"/>
                                </x-field>

                                <x-field label="Authorised by" name="authorised_by"
                                         :required="$requiresAuthoriser"
                                         help="Who approved the handover. Your company requires this.">
                                    <x-select name="authorised_by" :options="$people"
                                              :selected="old('authorised_by')"
                                              :required="$requiresAuthoriser" placeholder="Not stated"/>
                                </x-field>
                            </div>

                            <x-field label="Reason" name="reason"
                                     help="Why it is moving — a new job, a repair, an operator leaving.">
                                <x-input name="reason" :value="old('reason')" maxlength="300"
                                         placeholder="Mobilising to the Ikeja substation job"/>
                            </x-field>

                            <x-field label="Remarks" name="remarks"
                                     help="Damage, missing accessories, hours on the clock.">
                                <x-textarea name="remarks" :value="old('remarks')" rows="2"/>
                            </x-field>

                            <div class="flex items-center gap-3">
                                <button type="submit" class="btn btn-primary btn-sm">Record the handover</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="open = false">Cancel</button>
                            </div>
                        </form>
                    </div>
                @endif
            </div>

            {{-- The record of account behind the four columns above. --}}
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">Custody trail</h3>
                    <span class="text-xs text-[var(--text-muted)]">
                        {{ $asset->custodyTransfers->count() }} {{ Str::plural('handover', $asset->custodyTransfers->count()) }}
                    </span>
                </div>

                @if ($asset->custodyTransfers->isEmpty())
                    <x-empty-state icon="user" title="Never handed over"
                                   description="This asset has not been given to anybody yet. Recording a handover starts its trail."/>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Condition</th>
                                    <th>Authorised by</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($asset->custodyTransfers as $transfer)
                                    <tr>
                                        <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                                            {{ $transfer->transferred_at?->format('j M Y') }}
                                        </td>

                                        <td class="text-sm text-[var(--text-muted)]">
                                            {{ $transfer->isInitialAssignment() ? 'First issue' : ($transfer->fromLabel() ?: 'Nobody') }}
                                        </td>

                                        <td class="text-sm text-[var(--text-body)]">
                                            {{ $transfer->toLabel() }}
                                            @if ($transfer->reason)
                                                <span class="block text-xs text-[var(--text-muted)]">{{ $transfer->reason }}</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap text-sm">
                                            @if ($transfer->condition)
                                                <x-badge :tone="in_array($transfer->condition, ['poor', 'damaged'], true) ? 'warning' : 'neutral'">
                                                    {{ ucfirst($transfer->condition) }}
                                                </x-badge>
                                            @else
                                                <span class="text-[var(--text-muted)]">—</span>
                                            @endif
                                        </td>

                                        <td class="text-sm text-[var(--text-muted)]">
                                            {{ $transfer->authoriser?->name ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if ($canSeeMaintenance)
                <div class="card overflow-hidden">
                    <div class="card-header">
                        <h3 class="card-title">Maintenance</h3>
                        @can('create', App\Models\AssetMaintenance::class)
                            <a href="{{ route('asset-maintenances.create', ['asset' => $asset->id]) }}"
                               class="text-xs text-brand-600 hover:underline">Raise a job card</a>
                        @endcan
                    </div>

                    @if ($maintenances->isEmpty())
                        <x-empty-state icon="clipboard" title="No maintenance recorded"
                                       description="Nothing has been scheduled against this asset, and nothing has broken down that was written up."/>
                    @else
                        <div class="overflow-x-auto">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Job</th>
                                        <th>Kind</th>
                                        <th>Due</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($maintenances as $job)
                                        <tr>
                                            <td>
                                                <a href="{{ route('asset-maintenances.show', $job) }}"
                                                   class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                                    {{ $job->title }}
                                                </a>
                                                <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                    {{ $job->reference }}
                                                </span>
                                            </td>

                                            <td class="text-sm text-[var(--text-muted)]">
                                                {{ $job->is_planned ? 'Planned' : 'Breakdown' }}
                                            </td>

                                            <td class="whitespace-nowrap text-sm">
                                                @if ($job->due_on)
                                                    <span @class(['text-rose-600' => $job->isOverdue()])>
                                                        {{ $job->due_on->format('j M Y') }}
                                                    </span>
                                                @else
                                                    <span class="text-[var(--text-muted)]">—</span>
                                                @endif
                                            </td>

                                            <td class="whitespace-nowrap">
                                                @if ($job->status)
                                                    <x-badge :tone="$job->status->tone()">{{ $job->status->label }}</x-badge>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif

            <x-timeline :entries="$timeline" empty="Nothing has happened to this asset yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Particulars</h3></div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="Code" :value="$asset->code" mono/>
                    <x-register.fact label="Category" :value="$asset->category"/>
                    <x-register.fact label="Manufacturer" :value="$asset->manufacturer"/>
                    <x-register.fact label="Model" :value="$asset->model"/>
                    <x-register.fact label="Serial number" :value="$asset->serial_number" mono/>
                    <x-register.fact label="Tag number" :value="$asset->tag_number" mono/>
                    <x-register.fact label="Acquired" :value="$asset->acquired_on?->format('j M Y')"/>
                    <x-register.fact label="Purchase cost"
                                     :value="$asset->purchase_cost !== null ? number_format((float) $asset->purchase_cost, 2) : null" mono/>
                    <x-register.fact label="Supplier" :value="$asset->vendor?->name"/>

                    <x-register.fact label="Warranty">
                        @if ($asset->warranty_expires_on)
                            {{ $asset->warranty_expires_on->format('j M Y') }}
                            @if ($asset->warranty_expires_on->isPast())
                                <span class="text-[var(--text-muted)]">— expired</span>
                            @endif
                        @else
                            —
                        @endif
                    </x-register.fact>

                    @if ($asset->disposed_on)
                        <x-register.fact label="Disposed of" :value="$asset->disposed_on->format('j M Y')"/>
                        <x-register.fact label="Disposal reference" :value="$asset->disposal_reference" mono/>
                    @endif
                </dl>
            </div>

            @if ($asset->description || $asset->notes)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Notes</h3></div>
                    <div class="space-y-3 p-5 text-sm text-[var(--text-body)]">
                        @if ($asset->description)
                            <p class="whitespace-pre-line">{{ $asset->description }}</p>
                        @endif
                        @if ($asset->notes)
                            <p class="whitespace-pre-line text-[var(--text-muted)]">{{ $asset->notes }}</p>
                        @endif
                    </div>
                </div>
            @endif

            @if (count($transitions) || $canDispose || $canDelete)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Asset status</h3></div>
                    <div class="space-y-4 p-5">
                        @if (count($transitions))
                            <x-register.transitions :action="route('assets.transition', $asset)"
                                                    :transitions="$transitions" noun="asset"/>
                        @endif

                        @if ($canDispose && $asset->disposed_on === null)
                            {{-- Its own form rather than a status move, because retiring hands
                                 custody back, stamps the disposal date and asks for the reference
                                 the disposal was recorded under. `transition` re-routes here too. --}}
                            <form method="POST" action="{{ route('assets.retire', $asset) }}" class="space-y-3">
                                @csrf

                                <x-field label="Disposal reference" name="disposal_reference"
                                         help="A sale receipt, a scrap note, an insurance claim number.">
                                    <x-input name="disposal_reference" maxlength="120" class="input font-mono"/>
                                </x-field>

                                <x-field label="Reason" name="reason">
                                    <x-input name="reason" maxlength="300" placeholder="Beyond economic repair"/>
                                </x-field>

                                <button type="submit" class="btn btn-secondary btn-sm w-full">
                                    Retire this asset
                                </button>
                                <p class="help">
                                    Custody is handed back and the asset stops appearing in the working
                                    register. Its history stays exactly where it is.
                                </p>
                            </form>
                        @endif

                        @if ($canDelete)
                            <x-delete-form :action="route('assets.destroy', $asset)"
                                           label="Delete this asset"
                                           confirm="Deleting removes the asset record and its custody trail from the register. Retiring is almost always what you want instead. Continue?"/>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
