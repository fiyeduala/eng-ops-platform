{{--
    One vehicle: its papers, its driver, its clock and what it drinks.

    THE PAPERS COME FIRST, above everything else on the page, and lapsed ones are red at the top.
    This screen is opened most often by somebody about to send the vehicle out, and the question they
    have is not "what colour is it" — it is "is this legal to drive today".

    THREE WRITES, THREE RIGHTS, three separate little forms rather than one edit page: naming the
    driver is `vehicle.assign`, moving the clock is `vehicle.edit` through the one service that
    refuses a backwards reading, and everything else is the ordinary edit form. A single form that
    could post all three would route around two of them (§84).

    CONSUMPTION IS COMPUTED, NEVER STORED, and declines to answer when it cannot: it needs two full
    tanks with distance between them. A figure derived from a partial fill is wrong by an unknown
    amount and looks exactly like a right one.

    Expects: $vehicle, $documents, $fuelLogs, $timeline, $warningDays, $canEdit, $canAssign,
             $canLogFuel, $canFileDocument, $drivers
--}}
<x-layouts.app :title="$vehicle->registration_number">

    <x-page-header :title="$vehicle->registration_number"
                   :description="$vehicle->asset?->name">
        <x-slot:actions>
            @if ($vehicle->asset?->status)
                <x-badge :tone="$vehicle->asset->status->tone()">
                    {{ $vehicle->asset->status->label }}
                </x-badge>
            @endif

            @if ($vehicle->asset)
                <a href="{{ route('assets.show', $vehicle->asset) }}" class="btn btn-ghost btn-sm">
                    <x-icon name="package" class="h-3.5 w-3.5"/> {{ $vehicle->asset->code }}
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('vehicles.edit', $vehicle) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @php
        $lapsed = $documents->filter(fn ($doc) => $doc->isCurrent() && $doc->hasExpired());
    @endphp

    @if ($lapsed->isNotEmpty())
        <div class="card mb-5 border-rose-300 bg-rose-50 p-4 dark:border-rose-900 dark:bg-rose-950/40">
            <p class="text-sm font-medium text-rose-900 dark:text-rose-200">
                This vehicle should not leave the yard.
            </p>
            <p class="mt-1 text-sm text-rose-800 dark:text-rose-300">
                {{ $lapsed->map(fn ($doc) => $doc->typeLabel())->join(', ', ' and ') }}
                {{ $lapsed->count() === 1 ? 'has' : 'have' }} expired.
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Papers</h3>
                    @if ($canFileDocument)
                        <a href="{{ route('vehicle-documents.create', ['vehicle' => $vehicle->id]) }}"
                           class="btn btn-ghost btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> File a certificate
                        </a>
                    @endif
                </div>

                @if ($documents->isEmpty())
                    <x-empty-state
                        icon="file-text"
                        title="No certificates on file"
                        description="Either nothing has been filed for this vehicle, or the papers on it are outside your access."/>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Certificate</th>
                                    <th>Number</th>
                                    <th>Expires</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($documents as $doc)
                                    <tr>
                                        <td>
                                            <a href="{{ route('vehicle-documents.show', $doc) }}"
                                               class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                                {{ $doc->typeLabel() }}
                                            </a>
                                            @if ($doc->issuer)
                                                <span class="block text-xs text-[var(--text-muted)]">
                                                    {{ $doc->issuer }}
                                                </span>
                                            @endif
                                        </td>

                                        <td class="font-mono text-sm text-[var(--text-muted)]">
                                            {{ $doc->document_number ?: '—' }}
                                        </td>

                                        <td class="whitespace-nowrap text-sm">
                                            @if ($doc->expires_on)
                                                <span @class(['font-medium text-rose-600' => $doc->hasExpired()])>
                                                    {{ $doc->expires_on->format('j M Y') }}
                                                </span>
                                            @else
                                                <span class="text-[var(--text-muted)]">No expiry</span>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap text-right">
                                            @if (! $doc->isCurrent())
                                                <x-badge tone="neutral">Renewed</x-badge>
                                            @elseif ($doc->hasExpired())
                                                <x-badge tone="danger">Expired</x-badge>
                                            @elseif ($doc->expiresWithin($warningDays) && $doc->daysRemaining() !== null)
                                                <x-badge tone="warning">{{ $doc->daysRemaining() }} days</x-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Fuel</h3>
                    @if ($canLogFuel)
                        <a href="{{ route('fuel-logs.create', ['vehicle' => $vehicle->id]) }}"
                           class="btn btn-ghost btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Record a fill
                        </a>
                    @endif
                </div>

                @if ($fuelLogs->isEmpty())
                    <x-empty-state
                        icon="coins"
                        title="No fills recorded"
                        description="Fuel receipts for this vehicle either have not been entered or are outside your access."/>
                @else
                    @php
                        // Handed the loaded window rather than left to fetch its own: lazy loading is
                        // an exception in this application, and the fifteen newest fills are the ones
                        // the reader is looking at anyway.
                        $vehicle->setRelation('fuelLogs', $fuelLogs);
                        $consumption = $vehicle->consumptionPer100();
                    @endphp

                    <dl class="grid gap-4 border-b border-[var(--surface-border)] p-5 sm:grid-cols-3">
                        <x-register.fact label="Fills shown" :value="$fuelLogs->count()"/>
                        <x-register.fact label="Litres"
                                         :value="number_format((float) $fuelLogs->sum('litres'), 2)" mono/>
                        <x-register.fact label="Consumption">
                            @if ($consumption !== null)
                                <span class="font-mono text-sm">
                                    {{ number_format($consumption, 2) }} L / 100 {{ $vehicle->odometer_unit }}
                                </span>
                            @else
                                {{-- Saying so is the point. See consumptionPer100(). --}}
                                <span class="text-sm text-[var(--text-muted)]">
                                    Not enough full tanks to say
                                </span>
                            @endif
                        </x-register.fact>
                    </dl>

                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Filled</th>
                                    <th>Litres</th>
                                    <th>Odometer</th>
                                    <th>Cost</th>
                                    <th>Driver</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($fuelLogs as $log)
                                    <tr>
                                        <td class="whitespace-nowrap text-sm">
                                            {{ $log->filled_at?->format('j M Y') }}
                                            @unless ($log->is_full_tank)
                                                <x-badge tone="neutral">Partial</x-badge>
                                            @endunless
                                        </td>
                                        <td class="font-mono text-sm">{{ number_format((float) $log->litres, 2) }}</td>
                                        <td class="font-mono text-sm text-[var(--text-muted)]">
                                            {{ number_format((float) $log->odometer) }}
                                        </td>
                                        <td class="font-mono text-sm">
                                            {{ $log->total_cost !== null ? number_format((float) $log->total_cost, 2) : '—' }}
                                        </td>
                                        <td class="text-sm text-[var(--text-muted)]">
                                            {{ $log->driver?->name ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <x-timeline :entries="$timeline" empty="Nothing has happened to this vehicle yet."/>
        </div>

        <div class="space-y-6">

            <div class="card">
                <div class="card-header"><h3 class="card-title">Driver</h3></div>
                <div class="p-5">
                    @if ($canAssign)
                        <form method="POST" action="{{ route('vehicles.driver', $vehicle) }}" class="space-y-3">
                            @csrf

                            <x-field label="Answerable for this vehicle" name="assigned_driver_id"
                                     help="Leave blank to clear it. Only people you can reach are offered, and an id posted for anybody else is refused.">
                                <x-select name="assigned_driver_id" :options="$drivers"
                                          :selected="old('assigned_driver_id', $vehicle->assigned_driver_id)"
                                          placeholder="Nobody"/>
                            </x-field>

                            <button type="submit" class="btn btn-secondary btn-sm w-full">
                                Save the driver
                            </button>
                        </form>
                    @else
                        <p class="text-sm">
                            @if ($vehicle->assignedDriver)
                                <span class="font-medium text-[var(--text-strong)]">
                                    {{ $vehicle->assignedDriver->name }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">Nobody is named for this vehicle.</span>
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Odometer</h3></div>
                <div class="p-5">
                    <p class="mb-3 font-mono text-2xl text-[var(--text-strong)]">
                        {{ $vehicle->odometer() !== null ? number_format($vehicle->odometer()) : '—' }}
                        <span class="text-sm text-[var(--text-muted)]">{{ $vehicle->odometer_unit }}</span>
                    </p>

                    @if ($canEdit)
                        <form method="POST" action="{{ route('vehicles.odometer', $vehicle) }}" class="space-y-3">
                            @csrf

                            <x-field label="Reading now" name="current_odometer"
                                     help="It only ever goes up. A reading lower than the one recorded is refused rather than silently accepted.">
                                <x-input type="number" name="current_odometer" min="0" step="1" required/>
                            </x-field>

                            <button type="submit" class="btn btn-secondary btn-sm w-full">
                                Record the reading
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">Particulars</h3></div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="Plate" :value="$vehicle->registration_number" mono/>
                    <x-register.fact label="Kind" :value="$vehicle->vehicle_type"/>
                    <x-register.fact label="VIN" :value="$vehicle->vin" mono/>
                    <x-register.fact label="Year" :value="$vehicle->year_of_manufacture" mono/>
                    <x-register.fact label="Colour" :value="$vehicle->colour"/>
                    <x-register.fact label="Fuel" :value="$vehicle->fuel_type"/>
                    <x-register.fact label="Tank"
                                     :value="$vehicle->tank_capacity !== null ? number_format((float) $vehicle->tank_capacity, 2).' L' : null" mono/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">As an asset</h3></div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="In the custody of"
                                     :value="$vehicle->asset?->custodian?->name ?: 'Nobody'"/>
                    <x-register.fact label="Project" :value="$vehicle->asset?->project?->name"/>
                    <x-register.fact label="Unit" :value="$vehicle->asset?->orgUnit?->name"/>
                    <x-register.fact label="Location" :value="$vehicle->asset?->location?->name"/>
                </dl>
                <div class="border-t border-[var(--surface-border)] p-5 text-sm text-[var(--text-muted)]">
                    Custody, status and retirement are changed on the asset, not here — a lorry leaves
                    the fleet by being retired, which keeps its history attached to it.
                </div>
            </div>
        </div>
    </div>

</x-layouts.app>
