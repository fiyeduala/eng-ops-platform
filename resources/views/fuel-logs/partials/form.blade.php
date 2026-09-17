{{--
    A fuel receipt, entered or corrected.

    THE VEHICLE CANNOT BE CHANGED AFTERWARDS. Moving a receipt between trucks rewrites two
    consumption histories, and the second one silently. The request refuses it (`prohibited`); this
    form shows a disabled field on edit rather than pretending otherwise.

    LITRES AND WHAT WAS PAID, NOT A PRICE PER LITRE. A pump total is rounded, discounted and levied,
    and is frequently not litres times unit price. What was actually paid is the primary fact and the
    unit cost is derived from it — which is why there is no unit-cost box here to disagree with the
    receipt.

    THE ODOMETER MAY OR MAY NOT BE REQUIRED. A haulier lives by consumption per 100 km and wants the
    reading compulsory; a firm with two vans wants the spend and nothing else. The rule is a company
    setting enforced in the service, so a seeder and an import obey it too — this form asks for the
    reading either way and lets the service refuse a blank one if the company says so.

    Expects: $log, $vehicles, $projects, $drivers
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header"><h3 class="card-title">The fill</h3></div>
            <div class="space-y-5 p-5">
                @if ($log->exists)
                    <x-field label="Vehicle" name="vehicle_display"
                             help="A receipt cannot be moved to a different vehicle.">
                        <input type="text" id="vehicle_display" class="input font-mono" disabled
                               value="{{ $log->vehicle?->registration_number ?? 'Unknown vehicle' }}">
                    </x-field>
                @else
                    <x-field label="Vehicle" name="vehicle_id" required>
                        <x-select name="vehicle_id" :options="$vehicles"
                                  :selected="old('vehicle_id', $log->vehicle_id)" required
                                  placeholder="Choose the vehicle"/>
                    </x-field>
                @endif

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Filled at" name="filled_at" required
                             help="When fuel actually went in, not when the receipt was typed up.">
                        <x-input type="datetime-local" name="filled_at" required
                                 :value="old('filled_at', $log->filled_at?->format('Y-m-d\TH:i'))"/>
                    </x-field>

                    <x-field label="Litres" name="litres" required>
                        <x-input type="number" name="litres" step="0.001" min="0" required
                                 :value="old('litres', $log->litres)"/>
                    </x-field>

                    <x-field label="Odometer" name="odometer"
                             help="The reading on the clock at the pump. It moves the vehicle's own counter forward and is refused if it is lower than what is already recorded.">
                        <x-input type="number" name="odometer" min="0" step="1"
                                 :value="old('odometer', $log->odometer)"/>
                    </x-field>
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="is_full_tank" value="1" class="mt-0.5"
                           @checked(old('is_full_tank', $log->is_full_tank ?? true))>
                    <span>
                        <span class="font-medium text-[var(--text-strong)]">The tank was filled right up</span>
                        <span class="block text-[var(--text-muted)]">
                            Turn this off for a partial fill. Consumption is computed between two full
                            tanks and nothing else — a partial fill cannot tell you what went in before
                            it, so leaving this ticked when it was not true produces a wrong figure
                            that looks exactly like a right one.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">The receipt</h3></div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Total paid" name="total_cost"
                             help="What the receipt says, in the company's currency. The price per litre is worked out from it and never typed.">
                        <x-input type="number" name="total_cost" step="0.01" min="0"
                                 :value="old('total_cost', $log->total_cost)"/>
                    </x-field>

                    <x-field label="Receipt number" name="receipt_reference">
                        <x-input name="receipt_reference" maxlength="120" class="input font-mono"
                                 :value="old('receipt_reference', $log->receipt_reference)"/>
                    </x-field>
                </div>

                <x-field label="Station" name="station">
                    <x-input name="station" maxlength="200"
                             :value="old('station', $log->station)"
                             placeholder="Total, Ikorodu Road"/>
                </x-field>

                <x-field label="Notes" name="notes">
                    <x-textarea name="notes" :value="old('notes', $log->notes)" rows="3"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Who and what for</h3></div>
            <div class="space-y-5 p-5">
                <x-field label="Driver" name="driver_id"
                         help="Left blank on a new record it falls back to the vehicle's named driver, and then to you.">
                    <x-select name="driver_id" :options="$drivers"
                              :selected="old('driver_id', $log->driver_id)"
                              placeholder="The vehicle's driver"/>
                </x-field>

                <x-field label="Project" name="project_id"
                         help="What the fuel was burnt on. This is what puts diesel into a job's cost, so it is worth getting right.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $log->project_id)"
                              placeholder="Not against a project"/>
                </x-field>
            </div>
        </div>
    </div>
</div>
