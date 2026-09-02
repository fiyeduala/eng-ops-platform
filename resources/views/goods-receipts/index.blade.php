{{--
    What actually turned up — the goods receipt register.

    THIS IS WHERE THE CHAIN TOUCHES THE GROUND. Everything before it is paper: a requisition is a
    wish, an enquiry is a question, an order is a promise. A receipt is a storeman standing next to
    a pallet. That is why nothing on this register can be typed into existence from an order — the
    quantities come from a count, and the order's own status is recomputed from them.

    "DISPUTED" IS A TAB, NOT AN EXCEPTION. Goods arrive wrong every week. The register keeps them in
    view rather than letting a dispute sit unread behind a filter, because an order that reads as
    satisfied while its delivery is contested is how a supplier gets paid for the wrong thing.

    Expects: $goodsReceipts, $statuses, $projects, $locations, $views, $filters, $canCreate
--}}
<x-layouts.app title="Goods receipts">

    <x-page-header
        title="Goods receipts"
        description="What arrived, when, in what condition, and who counted it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('goods-receipts.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Book in a delivery
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="goods-receipts.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="goods-receipts.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, delivery note or carrier">
        <x-slot:extra>
            @if (count($locations))
                <div>
                    <label for="location" class="label">Where</label>
                    <x-select name="location" :options="$locations" :selected="$filters['location'] ?? null"
                              placeholder="Anywhere"/>
                </div>
            @endif

            @if (filled($filters['order'] ?? null))
                <input type="hidden" name="order" value="{{ $filters['order'] }}">
            @endif
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$goodsReceipts" icon="truck"
                      title="No deliveries"
                      description="Nothing has been booked in that your access reaches.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('goods-receipts.create') }}" class="btn btn-primary btn-sm">Book in a delivery</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Receipt</th>
                <th>Order</th>
                <th>Supplier</th>
                <th>Where</th>
                <th class="text-right">Lines</th>
                <th>Received</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($goodsReceipts as $receipt)
                <tr>
                    <td>
                        <a href="{{ route('goods-receipts.show', $receipt) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $receipt->number }}
                        </a>
                        @if ($receipt->delivery_note_ref)
                            {{-- Their delivery note number is what a dispute will be argued over,
                                 so it sits on the register rather than one click away. --}}
                            <span class="block text-xs text-[var(--text-muted)]">
                                Note {{ $receipt->delivery_note_ref }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        @if ($receipt->purchaseOrder)
                            <a href="{{ route('purchase-orders.show', $receipt->purchaseOrder) }}"
                               class="font-mono text-xs hover:text-brand-600">{{ $receipt->purchaseOrder->number }}</a>
                        @else
                            —
                        @endif
                    </td>

                    <td class="text-sm text-[var(--text-body)]">
                        {{ $receipt->purchaseOrder?->vendor?->name ?: '—' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">{{ $receipt->location?->name ?: '—' }}</td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">{{ $receipt->items_count }}</td>

                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        @if ($receipt->received_at)
                            {{ $receipt->received_at->format('j M Y') }}
                            @if ($receipt->receiver)
                                <span class="block text-xs">by {{ $receipt->receiver->name }}</span>
                            @endif
                        @else
                            Still being entered
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($receipt->status)
                            <x-badge :tone="$receipt->status->tone()">{{ $receipt->status->label }}</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
