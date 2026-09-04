{{--
    Booking in a delivery.

    THE ORDER COMES FIRST, AND THE FORM SAYS SO. A receipt without an order is a box in a yard that
    nobody can pay for, so when no order has been chosen this file renders a chooser and nothing
    else. It is a step rather than a field because the lines below are the ORDER'S lines: they
    cannot be drawn until the order is known, and letting somebody type quantities against nothing
    would only mean throwing them away on the next page.

    THE QUANTITIES ARE NOT PRE-FILLED. Every outstanding line is listed, and every one starts empty.
    Pre-filling the outstanding figure would turn "we counted it" into one click, which is exactly
    the click that gets a supplier paid for a pallet that never arrived. A line left at zero is not
    an omission — GoodsReceiptService drops it, so the order does not record a delivery of nothing.

    "STILL OWED" IS COMPUTED HERE, NOT READ OFF THE ITEM. PurchaseOrderItem::acceptedQuantity() sums
    every receipt including this one, which is right for the order page and wrong for this form: on
    an edit it would net off the very numbers being edited and read as though the goods were already
    booked. The figure below excludes this receipt, so it always means "what was owed before this
    note" — on a new receipt that exclusion matches nothing and the two agree.

    WHAT IS NOT ON THIS FORM: the number, the status, who received it and when. The receiver is the
    person who confirms the delivery and the timestamp is the moment they do it; both are recorded
    by the service, because a receipt whose receiver could be typed is not evidence of anything (§84).

    Expects: $goodsReceipt, $order, $orders, $locations, $conditions, $confidentialities, $nextNumber
--}}

@if (! $order)

    {{-- Step one. A GET, not a POST: nothing is created by choosing an order. --}}
    <div class="card mx-auto max-w-xl">
        <div class="card-header">
            <h2 class="card-title">Which order arrived?</h2>
        </div>

        <form method="GET" action="{{ route('goods-receipts.create') }}" class="space-y-5 p-5">
            <x-field label="Purchase order" name="order" required
                     help="Only orders that have been issued and are still expecting goods are listed.">
                <x-select name="order" :options="$orders" :selected="request('order')"
                          placeholder="Choose the order"/>
            </x-field>

            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('goods-receipts.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">Continue</button>
            </div>
        </form>
    </div>

@else

    @php
        // Merge what is already on this receipt with the order's lines, keyed by the order line so
        // an edit reopens on the same rows rather than appending a second set.
        $existing = collect($goodsReceipt->items ?? [])->keyBy('purchase_order_item_id');

        $lines = collect($order->items ?? [])->map(function ($item) use ($existing, $goodsReceipt) {
            $row = $existing->get($item->id);

            // Everything accepted against this order line by OTHER receipts. See the note above.
            $elsewhere = collect($item->receiptItems ?? [])
                ->reject(fn ($receiptItem) => $goodsReceipt->exists
                    && (int) $receiptItem->goods_receipt_id === (int) $goodsReceipt->id)
                ->sum(fn ($receiptItem) => (float) $receiptItem->quantity_accepted);

            return [
                'id' => $row?->id,
                'purchase_order_item_id' => $item->id,
                'description' => $item->description,
                'ordered' => \App\Support\Quantity::withUnit($item->quantity, $item->uom),
                'outstanding' => \App\Support\Quantity::trim(max(0, (float) $item->quantity - $elsewhere)),
                'quantity_accepted' => $row?->quantity_accepted,
                'quantity_rejected' => $row?->quantity_rejected,
                'condition' => $row?->condition,
                'rejection_reason' => $row?->rejection_reason,
                'batch_ref' => $row?->batch_ref,
                'expires_on' => $row?->expires_on,
                'notes' => $row?->notes,
            ];
        })->all();
    @endphp

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">The delivery</h2>
                </div>

                <div class="space-y-5 p-5">
                    {{-- The order is fixed for the life of the receipt. Moving a delivery to a
                         different order would leave its lines pointing at another order's items. --}}
                    <x-field label="Against order" name="purchase_order_id"
                             help="{{ $order->vendor?->name }}{{ $order->title ? ' — '.$order->title : '' }}">
                        <div class="input bg-[var(--surface-muted)] font-mono text-sm">{{ $order->number }}</div>
                        <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">
                    </x-field>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-field label="Their delivery note" name="delivery_note_ref"
                                 help="The number written on the supplier's paperwork.">
                            <x-input name="delivery_note_ref" :value="$goodsReceipt->delivery_note_ref"/>
                        </x-field>

                        <x-field label="Received at" name="location_id">
                            <x-select name="location_id" :options="$locations"
                                      :selected="$goodsReceipt->location_id" placeholder="Not recorded"/>
                        </x-field>

                        <x-field label="Carrier" name="carrier">
                            <x-input name="carrier" :value="$goodsReceipt->carrier"/>
                        </x-field>

                        <x-field label="Vehicle" name="vehicle_ref" help="Plate or waybill reference.">
                            <x-input name="vehicle_ref" :value="$goodsReceipt->vehicle_ref"/>
                        </x-field>
                    </div>

                    {{--
                        THE FIELD THAT PUTS THE GOODS ON A SHELF. Naming a store here is what makes
                        acceptance post to the stock ledger; leaving it empty records the delivery
                        without one, which is the right answer for anything going straight to site.
                        Only stores that are open are offered — see the controller.
                    --}}
                    @if (! empty($warehouses))
                        <x-field label="Into which store" name="warehouse_id"
                                 help="Accepting this delivery will add the accepted quantities to
                                       this store's stock. Leave it empty for goods going straight
                                       to site.">
                            <x-select name="warehouse_id" :options="$warehouses"
                                      :selected="$goodsReceipt->warehouse_id"
                                      placeholder="Not going into a store"/>
                        </x-field>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">What was counted</h2>
                    <p class="text-sm text-[var(--text-muted)]">
                        Every line still owed on the order is listed. Leave a line empty if nothing
                        against it arrived — it will not be recorded.
                    </p>
                </div>

                <div class="p-5">
                    {{-- `fixed`: the rows are the order's, so there is nothing to add or remove.
                         `description`, `ordered` and `outstanding` are static — they are shown so the
                         receiver knows what they are counting and post nothing back (§84). --}}
                    <x-line-items
                        name="items"
                        :rows="$lines"
                        fixed
                        :columns="[
                            ['key' => 'purchase_order_item_id', 'type' => 'hidden'],
                            ['key' => 'description', 'label' => 'Ordered item', 'type' => 'static', 'width' => 'w-64'],
                            ['key' => 'ordered', 'label' => 'Ordered', 'type' => 'static', 'width' => 'w-24'],
                            ['key' => 'outstanding', 'label' => 'Still owed', 'type' => 'static', 'width' => 'w-24'],
                            ['key' => 'quantity_accepted', 'label' => 'Accepted', 'type' => 'number', 'step' => '0.0001', 'min' => '0', 'width' => 'w-28'],
                            ['key' => 'quantity_rejected', 'label' => 'Refused', 'type' => 'number', 'step' => '0.0001', 'min' => '0', 'width' => 'w-28'],
                            ['key' => 'condition', 'label' => 'Condition', 'type' => 'select', 'options' => $conditions, 'width' => 'w-40'],
                            ['key' => 'rejection_reason', 'label' => 'Why refused', 'width' => 'w-56', 'help' => 'Required for anything you refuse.'],
                            ['key' => 'batch_ref', 'label' => 'Batch', 'width' => 'w-32'],
                            ['key' => 'expires_on', 'label' => 'Expires', 'type' => 'date', 'width' => 'w-40'],
                            ['key' => 'notes', 'label' => 'Notes', 'width' => 'w-56'],
                        ]"/>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Notes</h2>
                </div>

                <div class="space-y-5 p-5">
                    <x-field label="Inspection notes" name="inspection_notes"
                             help="What the inspection found. Recording it does not by itself mark the delivery inspected.">
                        <x-textarea name="inspection_notes" :value="$goodsReceipt->inspection_notes" rows="4"/>
                    </x-field>

                    <x-field label="Anything else" name="notes">
                        <x-textarea name="notes" :value="$goodsReceipt->notes" rows="3"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Filing</h2>
                </div>

                <div class="space-y-5 p-5">
                    <x-field label="Receipt number" name="number">
                        <div class="input bg-[var(--surface-muted)] font-mono text-sm">
                            {{ $goodsReceipt->number ?: $nextNumber }}
                        </div>
                    </x-field>

                    <x-field label="Confidentiality" name="confidentiality" required
                             help="A delivery is normally as open as the order it belongs to.">
                        <x-select name="confidentiality" :options="$confidentialities"
                                  :selected="$goodsReceipt->confidentiality ?? 'internal'"/>
                    </x-field>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">The order</h2>
                </div>

                <dl class="space-y-4 p-5">
                    <x-register.fact label="Order">
                        <a href="{{ route('purchase-orders.show', $order) }}"
                           class="font-mono hover:text-brand-600">{{ $order->number }}</a>
                    </x-register.fact>
                    <x-register.fact label="Supplier" :value="$order->vendor?->name"/>
                    <x-register.fact label="Project" :value="$order->project?->name"/>
                    <x-register.fact label="Wanted by"
                                     :value="$order->required_by?->format('j M Y')"/>
                </dl>
            </div>
        </div>
    </div>

@endif
