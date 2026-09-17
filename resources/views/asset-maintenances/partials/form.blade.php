{{--
    Raising a job card, or correcting one.

    THE COSTS ARE NOT ON THIS FORM. Labour, parts, downtime, the odometer and the findings are
    captured when the job is CERTIFIED COMPLETE, on the job card's own page, behind
    `asset_maintenance.complete`. A form that could type a cost in at scheduling time would let a
    job be closed with figures nobody stood behind — and signing a service off is the statement
    "this machine is safe to run again", read afterwards by an insurer and occasionally by an
    accident investigation (§84).

    THE ASSET CANNOT BE CHANGED AFTER THE FACT. Moving a job card to a different machine rewrites
    two maintenance histories, and the second one silently.

    DUE BY DATE OR DUE BY ODOMETER, and both may be blank. A generator is serviced every 500 running
    hours and an inspection is due in March; a breakdown is due now and has neither. Forcing one of
    them would produce a register full of invented dates.

    Expects: $maintenance, $assets, $people, $vendors, $types, $confidentialities
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header"><h3 class="card-title">The job</h3></div>
            <div class="space-y-5 p-5">
                @if ($maintenance->exists)
                    <x-field label="Asset" name="asset_display"
                             help="An asset cannot be changed once a job card has been raised against it.">
                        <input type="text" id="asset_display" class="input" disabled
                               value="{{ $maintenance->asset?->displayName() ?? 'Unknown asset' }}">
                    </x-field>
                @else
                    <x-field label="Asset" name="asset_id" required
                             help="Retired assets are not offered — scheduling a service on something the company no longer owns is never the intention.">
                        <x-select name="asset_id" :options="$assets"
                                  :selected="old('asset_id', $maintenance->asset_id)" required
                                  placeholder="Choose the machine"/>
                    </x-field>
                @endif

                <x-field label="What is being done" name="title" required
                         help="A person reading the register should know what the job is without opening it.">
                    <x-input name="title" :value="old('title', $maintenance->title)" required
                             placeholder="500-hour service — oil, filters, coolant"/>
                </x-field>

                <x-field label="Kind of work" name="maintenance_type"
                         help="Your own list — edit it in Settings.">
                    <x-select name="maintenance_type" :options="$types"
                              :selected="old('maintenance_type', $maintenance->maintenance_type)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Description" name="description"
                         help="The scope of work, the symptoms reported, whatever the person doing it needs.">
                    <x-textarea name="description" :value="old('description', $maintenance->description)" rows="4"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Who is doing it</h3></div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Our own person" name="performed_by"
                             help="Leave blank if it is going out to a workshop.">
                        <x-select name="performed_by" :options="$people"
                                  :selected="old('performed_by', $maintenance->performed_by)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Outside workshop" name="vendor_id"
                             help="Either or both — an in-house fitter often supervises external work.">
                        <x-select name="vendor_id" :options="$vendors"
                                  :selected="old('vendor_id', $maintenance->vendor_id)"
                                  placeholder="Not stated"/>
                    </x-field>
                </div>

                <x-field label="Work order reference" name="work_order_reference"
                         help="Their job number, so the invoice can be matched to this card later.">
                    <x-input name="work_order_reference" maxlength="120" class="input font-mono"
                             :value="old('work_order_reference', $maintenance->work_order_reference)"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">When it is due</h3></div>
            <div class="space-y-5 p-5">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="is_planned" value="1" class="mt-0.5"
                           @checked(old('is_planned', $maintenance->is_planned ?? true))>
                    <span>
                        <span class="font-medium text-[var(--text-strong)]">Planned work</span>
                        <span class="block text-[var(--text-muted)]">
                            Turn this off for a breakdown. Unplanned work is counted separately,
                            because "how often does this machine fail" is a different question from
                            "are we servicing it on time".
                        </span>
                    </span>
                </label>

                <x-field label="Due on" name="due_on"
                         help="A date, compared against today whenever it is asked. Nothing marks a job overdue — it either is or it is not.">
                    <x-input type="date" name="due_on"
                             :value="old('due_on', $maintenance->due_on?->toDateString())"/>
                </x-field>

                <x-field label="Due at odometer" name="due_odometer"
                         help="For plant serviced by hours run or distance covered rather than by the calendar.">
                    <x-input type="number" name="due_odometer" min="0" step="1"
                             :value="old('due_odometer', $maintenance->due_odometer)"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Access</h3></div>
            <div class="p-5">
                <x-field label="Confidentiality" name="confidentiality" required
                         help="Job cards are internal by default. Restricting one hides it from everybody outside the unit that owns the asset.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $maintenance->confidentiality?->value)" required/>
                </x-field>
            </div>
        </div>

        @if (! $maintenance->exists)
            <div class="card border-brand-200 bg-brand-50/50 p-4 text-sm text-[var(--text-muted)]
                        dark:border-brand-900 dark:bg-brand-950/30">
                Costs, downtime and findings are recorded when the job is signed off, not now.
            </div>
        @endif
    </div>
</div>
