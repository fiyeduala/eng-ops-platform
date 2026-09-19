{{--
    Raising an order, or correcting one.

    AN ORDER IS A PROMISE TO PAY SOMEBODY. That is the whole reason this form is stricter than the
    requisition: prices are real, the supplier is real, and the delivery address is where a lorry
    will actually go. Nothing on it commits anybody until it is approved and issued — those are two
    separate acts on the order's own page, and neither is a field here (§59, §84).

    RAISED FROM AN AWARD, THE LINES COME ACROSS. `$quotation` is the winning quotation this order
    was started from; it was resolved through the buyer's own scoped query, so it is here only if
    they could open it anyway. PurchaseOrderService copies its lines, its currency and its rate, so
    the grid is shown as a read-only preview rather than as an editable thing that gets discarded.

    PICKING A QUOTATION LATER DOES THE SAME. Choosing an awarded quotation in the select below sends
    the order down the same path on save, and whatever was typed into the grid is replaced by the
    quotation's own lines. Said here rather than discovered afterwards.

    THE TOTAL IS NOT A FIELD. Subtotal is the sum of the lines and total is subtotal plus the tax
    figure, both written by the service, so an order whose total disagrees with its lines cannot be
    saved into existence.

    Expects: $purchaseOrder, $quotation, $projects, $units, $people, $confidentialities, $vendors,
             $locations, $quotations, $purchaseRequests, $contracts
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What is being ordered, and from whom</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required
                         help="What this order is for, in the words the supplier will recognise.">
                    <x-input name="title" :value="old('title', $purchaseOrder->title)" required
                             placeholder="Pipe supports and fixings — Area 3"/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Supplier" name="vendor_id" required
                             help="Raised from an award, this is the supplier who won and cannot be changed here.">
                        @if ($quotation)
                            <p class="text-sm text-[var(--text-body)]">{{ $quotation->vendor?->name }}</p>
                            <input type="hidden" name="vendor_id" value="{{ $quotation->vendor_id }}">
                        @else
                            <x-select name="vendor_id" :options="$vendors"
                                      :selected="old('vendor_id', $purchaseOrder->vendor_id)"
                                      placeholder="Choose a supplier" required/>
                        @endif
                    </x-field>

                    <x-field label="Against the award" name="quotation_id"
                             help="Awarded quotations only. Choosing one brings its lines, currency and rate across and replaces anything typed below.">
                        @if ($quotation)
                            <p class="text-sm text-[var(--text-body)]">
                                {{ $quotation->number }}{{ $quotation->reference ? ' — '.$quotation->reference : '' }}
                            </p>
                            <input type="hidden" name="quotation_id" value="{{ $quotation->id }}">
                        @else
                            <x-select name="quotation_id" :options="$quotations"
                                      :selected="old('quotation_id', $purchaseOrder->quotation_id)"
                                      placeholder="Not against a quotation"/>
                        @endif
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Against requisition" name="purchase_request_id"
                             help="Approved requisitions only. This is the link that lets the chain be followed end to end.">
                        <x-select name="purchase_request_id" :options="$purchaseRequests"
                                  :selected="old('purchase_request_id', $purchaseOrder->purchase_request_id)"
                                  placeholder="Not against a requisition"/>
                    </x-field>

                    <x-field label="Under contract" name="contract_id"
                             help="Supply contracts only, and only the supplier's own. A call-off against a framework belongs here.">
                        <x-select name="contract_id" :options="$contracts"
                                  :selected="old('contract_id', $purchaseOrder->contract_id)"
                                  placeholder="Not under a contract"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Ordered on" name="ordered_on">
                        <x-input type="date" name="ordered_on"
                                 :value="old('ordered_on', $purchaseOrder->ordered_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Required by" name="required_by"
                             help="When the goods are needed on site. The register turns this red once it has passed and nothing has arrived.">
                        <x-input type="date" name="required_by"
                                 :value="old('required_by', $purchaseOrder->required_by?->format('Y-m-d'))"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The lines</h3>
                <span class="text-xs text-[var(--text-muted)]">Totals are worked out from these</span>
            </div>
            <div class="space-y-4 p-5">
                @if ($quotation)
                    {{-- Copied by the service from the winning quotation, each line keeping its link
                         back to the quotation line and, through it, to the requisition line it began
                         as. That chain is the point; an editable grid here would break it quietly. --}}
                    <div class="rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] p-4">
                        <p class="text-sm text-[var(--text-body)]">
                            These lines come across from
                            <a href="{{ route('quotations.show', $quotation) }}"
                               class="font-medium hover:text-brand-600">{{ $quotation->number }}</a>
                            at the prices that were awarded. Save the order first, then edit the
                            lines here if something needs correcting.
                        </p>
                    </div>

                    <ul class="divide-y divide-[var(--surface-border)] text-sm">
                        @foreach ($quotation->items as $item)
                            <li class="flex items-start justify-between gap-4 py-2">
                                <span class="text-[var(--text-body)]">{{ $item->description }}</span>
                                <span class="whitespace-nowrap font-mono text-xs text-[var(--text-muted)]">
                                    {{ \App\Support\Quantity::withUnit($item->quantity, $item->uom) }}
                                    @ {{ number_format((float) $item->unit_price, 2) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <x-line-items
                        :rows="$purchaseOrder->items ?? []"
                        add-label="Add a line"
                        quantity-key="quantity"
                        rate-key="unit_price"
                        :currency="old('currency', $purchaseOrder->currency)"
                        :columns="[
                            ['key' => 'quotation_item_id', 'label' => '', 'type' => 'hidden'],
                            ['key' => 'purchase_request_item_id', 'label' => '', 'type' => 'hidden'],
                            ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => 'w-[28%]', 'placeholder' => 'Galvanised U-bolt, M16'],
                            ['key' => 'specification', 'label' => 'Specification', 'type' => 'textarea', 'width' => 'w-[20%]', 'placeholder' => 'Standard, grade, drawing ref'],
                            ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number', 'step' => 'any', 'min' => '0', 'width' => 'w-24'],
                            ['key' => 'uom', 'label' => 'Unit', 'type' => 'text', 'width' => 'w-20', 'placeholder' => 'no.'],
                            ['key' => 'unit_price', 'label' => 'Unit price', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'width' => 'w-32'],
                            ['key' => 'required_by', 'label' => 'Required', 'type' => 'date', 'width' => 'w-36'],
                            ['key' => 'notes', 'label' => 'Note', 'type' => 'text', 'width' => 'w-[14%]'],
                        ]"/>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Delivery and terms</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Deliver to" name="location_id"
                             help="The site or store the goods are going to. It is what the receipt will be booked against.">
                        <x-select name="location_id" :options="$locations"
                                  :selected="old('location_id', $purchaseOrder->location_id)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Payment terms" name="payment_terms">
                        <x-input name="payment_terms" :value="old('payment_terms', $purchaseOrder->payment_terms)"
                                 placeholder="30 days from invoice"/>
                    </x-field>
                </div>

                <x-field label="Delivery address" name="delivery_address"
                         help="Written out for the supplier, including the gate, the contact and the hours. A location code means nothing to their driver.">
                    <x-textarea name="delivery_address" :value="old('delivery_address', $purchaseOrder->delivery_address)" rows="3"
                                placeholder="Gate 2, Tank Farm, Plot 14 Trans-Amadi, Port Harcourt. Deliveries 08:00–16:00, ask for the materials controller."/>
                </x-field>

                <x-field label="Delivery terms" name="delivery_terms">
                    <x-input name="delivery_terms" :value="old('delivery_terms', $purchaseOrder->delivery_terms)"
                             placeholder="Delivered to site, offloading by others"/>
                </x-field>

                <x-field label="Notes" name="notes"
                         help="For your colleagues and for the supplier's copy of the order.">
                    <x-textarea name="notes" :value="old('notes', $purchaseOrder->notes)" rows="3"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it belongs</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Project" name="project_id"
                         help="What the spend is against. It is how this order reaches the project's cost report.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $purchaseOrder->project_id)"
                              placeholder="Not project-specific"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id"
                         help="Whose order this is. It decides who can read it.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $purchaseOrder->org_unit_id)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required>
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $purchaseOrder->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Money</h3>
            </div>
            <div class="space-y-5 p-5">
                @if ($quotation)
                    {{-- Currency, rate and tax are copied from the award and never recomputed: the
                         order must commit the company to the figures that were actually accepted. --}}
                    <p class="text-sm text-[var(--text-muted)]">
                        Currency, exchange rate and tax come across from
                        {{ $quotation->number }} exactly as awarded — {{ $quotation->currency }},
                        and nothing here revalues them.
                    </p>
                @else
                    <x-field label="Currency" name="currency"
                             help="Three letters. Leave it as your own currency unless the order is placed in another.">
                        <x-input name="currency" :value="old('currency', $purchaseOrder->currency)"
                                 maxlength="3" placeholder="NGN"/>
                    </x-field>

                    <x-field label="Exchange rate" name="exchange_rate"
                             help="Fixed on the order and never updated, so what was committed stays what was committed.">
                        <x-input type="number" name="exchange_rate" step="0.000001" min="0"
                                 :value="old('exchange_rate', $purchaseOrder->exchange_rate)"/>
                    </x-field>

                    <x-field label="Tax" name="tax_amount"
                             help="The figure as it will appear on the order, whatever it is called locally.">
                        <x-input type="number" name="tax_amount" step="0.01" min="0"
                                 :value="old('tax_amount', $purchaseOrder->tax_amount)"/>
                    </x-field>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Next</h3>
            </div>
            <div class="p-5">
                <p class="text-sm text-[var(--text-muted)]">
                    Saving this creates a draft. It goes for approval, is approved by somebody other
                    than whoever submitted it, and only then is issued to the supplier — the moment
                    the company is committed.
                </p>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="purchase_order" :record="$purchaseOrder" class="card mt-5"/>
