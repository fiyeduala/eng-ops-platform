{{--
    The orders placed — commitments the company has actually made.

    THIS IS THE ONLY PROCUREMENT REGISTER WHERE THE MONEY COLUMN BELONGS. A requisition carries an
    estimate and a quotation carries an offer; an order carries a commitment somebody outside the
    company is acting on. Showing the figure here is reporting spend, which is what it is.

    "AWAITING DELIVERY" AND "OVERDUE" COME FROM THE RECEIPTS, NOT FROM A FLAG. `part_received` and
    `received` are written by GoodsReceiptService from the arithmetic of what arrived, so an order
    is late when its required date has passed and the goods have not — a person cannot mark an order
    delivered any more than they can mark a lorry as having turned up.

    Expects: $purchaseOrders, $statuses, $projects, $vendors, $views, $filters, $canCreate
--}}
<x-layouts.app title="Purchase orders">

    <x-page-header
        title="Purchase orders"
        description="What has been ordered, from whom, for how much and when it is due.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('purchase-orders.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise an order
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="purchase-orders.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="purchase-orders.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or delivery address">
        <x-slot:extra>
            @if (count($vendors))
                <div>
                    <label for="vendor" class="label">Supplier</label>
                    <x-select name="vendor" :options="$vendors" :selected="$filters['vendor'] ?? null"
                              placeholder="Any supplier"/>
                </div>
            @endif
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$purchaseOrders" icon="cart"
                      title="No purchase orders"
                      description="Nothing has been ordered that your access reaches.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('purchase-orders.create') }}" class="btn btn-primary btn-sm">Raise an order</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Order</th>
                <th>Supplier</th>
                <th>Project</th>
                <th class="text-right">Lines</th>
                <th class="text-right">Value</th>
                <th>Required by</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($purchaseOrders as $order)
                <tr>
                    <td>
                        <a href="{{ route('purchase-orders.show', $order) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $order->title }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $order->number }}</span>
                    </td>

                    <td class="text-sm text-[var(--text-body)]">{{ $order->vendor?->name ?: '—' }}</td>

                    <td class="text-sm text-[var(--text-muted)]">{{ $order->project?->name ?: '—' }}</td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">{{ $order->items_count }}</td>

                    <td class="whitespace-nowrap text-right font-mono text-sm text-[var(--text-strong)]">
                        {{ number_format((float) $order->total, 2) }}
                        <span class="text-xs text-[var(--text-muted)]">{{ $order->currency }}</span>
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($order->required_by)
                            {{-- Late is late only while something is still expected. A closed order
                                 with an old date is history, not a problem. --}}
                            <span @class([
                                'font-medium text-rose-600' => $order->required_by->isPast()
                                    && ! $order->status?->is_terminal
                                    && ! in_array($order->statusKey(), ['received'], true),
                                'text-[var(--text-muted)]' => ! $order->required_by->isPast()
                                    || $order->status?->is_terminal,
                            ])>{{ $order->required_by->format('j M Y') }}</span>
                        @else
                            <span class="text-[var(--text-muted)]">Not stated</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($order->status)
                            <x-badge :tone="$order->status->tone()">{{ $order->status->label }}</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
