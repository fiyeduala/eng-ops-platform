{{--
    Attaching a plate to an asset, or correcting the particulars of one that has one.

    IT ALWAYS STARTS FROM AN ASSET. There is no "add a vehicle" that does not first add a thing the
    company owns. The picker offers assets that are not disposed of and do not already carry a plate;
    on edit it becomes a disabled display field, because moving a plate to a different machine is not
    a correction, it is two mistakes — and the request refuses it outright (`prohibited`).

    THE DRIVER IS NOT ON THIS FORM. Whose licence is on the line when a lorry is stopped sits behind
    `vehicle.assign`, on the vehicle's own page. A transport clerk who may fix a tyre size should not
    be able, by the same right, to put somebody else's name on a truck (§84).

    THE ODOMETER IS ACCEPTED ONCE AND NEVER AGAIN. On create it is the reading on the clock the day
    the vehicle arrived — a second-hand pickup starts wherever it starts. Afterwards the counter moves
    only through a fill, a completed service, or the reading form on the vehicle page, all of which go
    through the one writer that refuses to let it run backwards.

    Expects: $vehicle, $assetId, $assets, $types, $fuelTypes, $odometerUnits
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header"><h3 class="card-title">The vehicle</h3></div>
            <div class="space-y-5 p-5">
                @if ($vehicle->exists)
                    <x-field label="Asset" name="asset_display"
                             help="A plate cannot be moved to a different asset.">
                        <input type="text" id="asset_display" class="input" disabled
                               value="{{ $vehicle->asset?->displayName() ?? 'Unknown asset' }}">
                    </x-field>
                @else
                    <x-field label="Asset" name="asset_id" required
                             help="Only assets that are still owned and do not already carry a plate are offered.">
                        <x-select name="asset_id" :options="$assets"
                                  :selected="old('asset_id', $assetId)" required
                                  placeholder="Choose the machine"/>
                    </x-field>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Registration number" name="registration_number" required
                             help="Spaces and dashes are removed and the plate is upper-cased, so ABC 123 XY and abc-123-xy are one vehicle.">
                        <x-input name="registration_number" required maxlength="40"
                                 class="input font-mono uppercase"
                                 :value="old('registration_number', $vehicle->registration_number)"
                                 placeholder="ABC123XY"/>
                    </x-field>

                    <x-field label="VIN / chassis number" name="vin">
                        <x-input name="vin" maxlength="60" class="input font-mono"
                                 :value="old('vin', $vehicle->vin)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Kind" name="vehicle_type"
                             help="Your own list — edit it in Settings.">
                        <x-select name="vehicle_type" :options="$types"
                                  :selected="old('vehicle_type', $vehicle->vehicle_type)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Year" name="year_of_manufacture">
                        <x-input type="number" name="year_of_manufacture" min="1900"
                                 max="{{ now()->year + 1 }}"
                                 :value="old('year_of_manufacture', $vehicle->year_of_manufacture)"/>
                    </x-field>

                    <x-field label="Colour" name="colour">
                        <x-input name="colour" maxlength="40"
                                 :value="old('colour', $vehicle->colour)"/>
                    </x-field>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Fuel and the clock</h3></div>
            <div class="space-y-5 p-5">
                <x-field label="Fuel" name="fuel_type">
                    <x-select name="fuel_type" :options="$fuelTypes"
                              :selected="old('fuel_type', $vehicle->fuel_type)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Tank capacity" name="tank_capacity"
                         help="In litres. Used to sanity-check a fill, never to reject one — a jerrycan on the back is real.">
                    <x-input type="number" name="tank_capacity" step="0.01" min="0"
                             :value="old('tank_capacity', $vehicle->tank_capacity)"/>
                </x-field>

                <x-field label="Metered in" name="odometer_unit" required
                         help="Plant is serviced by running hours, not distance. Choosing hours here is what stops a 250-hour interval being reported as 250 kilometres.">
                    <x-select name="odometer_unit" :options="$odometerUnits"
                              :selected="old('odometer_unit', $vehicle->odometer_unit ?? 'km')" required/>
                </x-field>

                @if ($vehicle->exists)
                    <x-field label="Odometer now" name="odometer_display"
                             help="Moved by a fill, a completed service, or the reading form on this vehicle's page — never by this form.">
                        <input type="text" id="odometer_display" class="input font-mono" disabled
                               value="{{ $vehicle->odometer() !== null
                                    ? number_format($vehicle->odometer()).' '.$vehicle->odometer_unit
                                    : 'No reading recorded' }}">
                    </x-field>
                @else
                    <x-field label="Odometer on arrival" name="current_odometer"
                             help="The reading on the clock today. It is the only time this figure can be typed; afterwards it only ever goes up.">
                        <x-input type="number" name="current_odometer" min="0" step="1"
                                 :value="old('current_odometer')"/>
                    </x-field>
                @endif
            </div>
        </div>

        <div class="card border-brand-200 bg-brand-50/50 p-4 text-sm text-[var(--text-muted)]
                    dark:border-brand-900 dark:bg-brand-950/30">
            Who owns it, whose custody it is in, what it cost and whether it is still in service are
            all recorded against the
            @if ($vehicle->exists && $vehicle->asset)
                <a href="{{ route('assets.show', $vehicle->asset) }}"
                   class="font-medium text-brand-700 hover:underline dark:text-brand-300">asset</a>,
            @else
                asset,
            @endif
            not here.
        </div>
    </div>
</div>
