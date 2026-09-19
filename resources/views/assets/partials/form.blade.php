{{--
    Adding an asset, or correcting one.

    FOUR THINGS ARE ABSENT FROM THIS FORM AND ALL FOUR ARE DELIBERATE.

    Custody — who has it, which project, which unit, which location — is not here. Those four columns
    are a CACHE of the last row in the custody trail, and a form that could set them directly would
    let somebody move a generator to another site with no record of who authorised it or what
    condition it was in. Handover has its own form on the asset's own page, behind its own
    permission, and it writes a trail row first (§84).

    Status is not here either: an asset is commissioned, taken out of service and retired through
    the status graph, and retirement additionally hands custody back and stamps the disposal date.

    `is_vehicle` is not here. It is set by attaching a plate on the fleet register, which creates the
    vehicle row in the same transaction — a tickbox would produce assets that claim to be vehicles
    with nothing to drive.

    And the disposal date is not here, because an asset stops being owned by being retired, not by
    somebody typing a date into a text box.

    THE CODE IS TYPED, NOT GENERATED. It is stencilled on the machine and read by people who never
    open this system, so it has to be the word already painted on the side.

    Expects: $asset, $categories, $vendors, $confidentialities
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header"><h3 class="card-title">The asset</h3></div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Code" name="code" required
                             help="What is stencilled on the machine. Upper-cased on save.">
                        <x-input name="code" :value="old('code', $asset->code)" required
                                 maxlength="60" class="input font-mono uppercase" placeholder="GEN-040"/>
                    </x-field>

                    <x-field label="Name" name="name" required class="sm:col-span-2"
                             help="What people call it when they are standing next to it.">
                        <x-input name="name" :value="old('name', $asset->name)" required
                                 placeholder="40kVA site generator"/>
                    </x-field>
                </div>

                <x-field label="Category" name="category"
                         help="Your own list — edit it in Settings. Nothing in the system behaves differently per category; it is how people find things.">
                    <x-select name="category" :options="$categories"
                              :selected="old('category', $asset->category)" placeholder="Not stated"/>
                </x-field>

                <x-field label="Description" name="description"
                         help="Capacity, attachments, anything that distinguishes this one from the identical one next to it.">
                    <x-textarea name="description" :value="old('description', $asset->description)" rows="3"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Identification</h3></div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Manufacturer" name="manufacturer">
                        <x-input name="manufacturer" :value="old('manufacturer', $asset->manufacturer)"
                                 placeholder="Caterpillar"/>
                    </x-field>

                    <x-field label="Model" name="model">
                        <x-input name="model" :value="old('model', $asset->model)" placeholder="DE40E0"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Serial number" name="serial_number"
                             help="Not required to be unique. Two identical pumps genuinely share a manufacturer's number often enough that refusing the second would only teach people to invent one.">
                        <x-input name="serial_number" :value="old('serial_number', $asset->serial_number)"
                                 maxlength="120" class="input font-mono"/>
                    </x-field>

                    <x-field label="Tag number" name="tag_number"
                             help="The company's own asset tag, if it uses them alongside the code.">
                        <x-input name="tag_number" :value="old('tag_number', $asset->tag_number)"
                                 maxlength="120" class="input font-mono"/>
                    </x-field>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">How it was acquired</h3></div>
            <div class="space-y-5 p-5">
                @isset ($receiptItem)
                    <p class="text-sm text-[var(--text-muted)]">
                        Order price: {{ $receiptItem->purchaseOrderItem->purchaseOrder->currency }} {{ number_format((float) $receiptItem->purchaseOrderItem->unit_price, 2) }}.
                        Foreign currency is converted at the purchase order's recorded exchange rate.
                        The supplier and acquisition date are copied from the purchase order and delivery.
                    </p>
                @else
                <x-field label="Acquired on" name="acquired_on">
                    <x-input type="date" name="acquired_on"
                             :value="old('acquired_on', $asset->acquired_on?->toDateString())"/>
                </x-field>

                <x-field label="Purchase cost" name="purchase_cost"
                         help="What was paid, in the company's currency. Nothing is depreciated from it — this register records what exists, not what it is worth today.">
                    <x-input type="number" name="purchase_cost" step="0.01" min="0"
                             :value="old('purchase_cost', $asset->purchase_cost)"/>
                </x-field>

                <x-field label="Supplier" name="vendor_id"
                         help="Who it was bought from. Useful when it needs a part in three years.">
                    <x-select name="vendor_id" :options="$vendors"
                              :selected="old('vendor_id', $asset->vendor_id)" placeholder="Not stated"/>
                </x-field>
                @endisset

                <x-field label="Warranty expires" name="warranty_expires_on"
                         help="A date, not a status. Nothing marks it expired — it is compared against today whenever it is asked.">
                    <x-input type="date" name="warranty_expires_on"
                             :value="old('warranty_expires_on', $asset->warranty_expires_on?->toDateString())"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Access</h3></div>
            <div class="space-y-5 p-5">
                <x-field label="Confidentiality" name="confidentiality" required
                         help="Assets are internal by default. Restricting one hides it from everybody outside the unit that owns it.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $asset->confidentiality?->value)" required/>
                </x-field>

                <x-field label="Notes" name="notes"
                         help="Anything a person taking custody of it should know.">
                    <x-textarea name="notes" :value="old('notes', $asset->notes)" rows="3"/>
                </x-field>
            </div>
        </div>

        @if (! $asset->exists)
            <div class="card border-brand-200 bg-brand-50/50 p-4 text-sm text-[var(--text-muted)]
                        dark:border-brand-900 dark:bg-brand-950/30">
                A new asset is held by nobody until it is handed over. Save it first, then use
                <span class="font-medium text-[var(--text-strong)]">Hand over</span> on its page to
                start its custody trail.
            </div>
        @endif
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="asset" :record="$asset" class="card mt-5"/>
