{{--
    One catalogue item: where it is, and what has been happening to it.

    THE BREAKDOWN BY STORE IS THE POINT OF THIS PAGE. "We have 400 metres" is rarely the answer
    anybody needs; "340 in the Lagos yard and 60 on site" is.

    THE UNIT CANNOT BE CHANGED ONCE STOCK HAS MOVED IN IT, and the form says so rather than the
    save quietly failing. Re-basing metres to rolls halfway through a ledger silently rewrites
    every balance ever posted.

    Expects: $stockItem, $balances, $movements, $timeline, $transitions, $canEdit, $canDelete,
             $canSeeValue
--}}
<x-layouts.app :title="$stockItem->name">

    <x-page-header :title="$stockItem->name" :description="$stockItem->code">
        <x-slot:actions>
            @if ($stockItem->is_hazardous)
                <x-badge tone="danger">Hazardous</x-badge>
            @endif
            @if ($stockItem->status)
                <x-badge :tone="$stockItem->status->tone()">{{ $stockItem->status->label }}</x-badge>
            @endif

            <a href="{{ route('stock.index', ['item' => $stockItem->id]) }}" class="btn btn-ghost btn-sm">
                Full history
            </a>

            @if ($canEdit)
                <a href="{{ route('stock-items.edit', $stockItem) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">Where it is</h3>
                    <span class="text-xs text-[var(--text-muted)]">Counted in {{ $stockItem->uom }}</span>
                </div>

                @if ($balances->isEmpty())
                    <x-empty-state icon="archive" title="Not held anywhere"
                                   description="No store has a balance of this item. That is the ordinary state for something newly catalogued, or for an item bought straight to the job."/>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Store</th>
                                    <th class="text-right">On hand</th>
                                    <th class="text-right">Reserved</th>
                                    <th class="text-right">Available</th>
                                    @if ($canSeeValue)
                                        <th class="text-right">Value</th>
                                    @endif
                                    <th>Last counted</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($balances as $balance)
                                    <tr>
                                        <td>
                                            <a href="{{ route('warehouses.show', $balance->warehouse_id) }}"
                                               class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                                {{ $balance->warehouse?->name ?? 'Unknown store' }}
                                            </a>
                                            <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                {{ $balance->warehouse?->code }}
                                            </span>
                                        </td>
                                        <td class="text-right font-mono text-sm">
                                            {{ rtrim(rtrim(number_format((float) $balance->quantity_on_hand, 4), '0'), '.') }}
                                        </td>
                                        <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                                            {{ rtrim(rtrim(number_format((float) $balance->quantity_reserved, 4), '0'), '.') }}
                                        </td>
                                        <td class="text-right font-mono text-sm">
                                            {{ rtrim(rtrim(number_format($balance->available(), 4), '0'), '.') }}
                                        </td>
                                        @if ($canSeeValue)
                                            <td class="text-right font-mono text-sm">
                                                {{ number_format((float) $balance->total_value, 2) }}
                                            </td>
                                        @endif
                                        <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                                            {{ $balance->last_counted_at?->format('j M Y') ?: 'Never' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">Recent movements</h3>
                    <a href="{{ route('stock.index', ['item' => $stockItem->id]) }}"
                       class="text-xs text-brand-600 hover:underline">The whole ledger</a>
                </div>

                @if ($movements->isEmpty())
                    <x-empty-state icon="truck" title="Nothing has moved"
                                   description="This item has never been received, issued or counted."/>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>What</th>
                                    <th>Store</th>
                                    <th class="text-right">Quantity</th>
                                    <th>By</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($movements as $movement)
                                    <tr>
                                        <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                                            {{ $movement->occurred_at?->format('j M Y') }}
                                        </td>
                                        <td>
                                            <a href="{{ route('stock.show', $movement) }}"
                                               class="text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                                {{ $movement->movementLabel() }}
                                            </a>
                                            <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                {{ $movement->number }}
                                            </span>
                                        </td>
                                        <td class="text-sm text-[var(--text-muted)]">
                                            {{ $movement->warehouse?->code }}
                                        </td>
                                        <td class="text-right font-mono text-sm">
                                            {{-- The sign is the fact. A ledger that showed every
                                                 movement as a positive number and left the reader
                                                 to infer the direction from the word beside it
                                                 would be a ledger nobody could add up. --}}
                                            <span @class([
                                                'text-emerald-600' => $movement->isInbound(),
                                                'text-rose-600' => ! $movement->isInbound(),
                                            ])>
                                                {{ rtrim(rtrim(number_format((float) $movement->quantity, 4), '0'), '.') }}
                                            </span>
                                        </td>
                                        <td class="text-sm text-[var(--text-muted)]">
                                            {{ $movement->creator?->name ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <x-timeline :entries="$timeline" empty="Nothing has happened to this catalogue entry yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Particulars</h3></div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="Code" :value="$stockItem->code" mono/>
                    <x-register.fact label="Unit of measure" :value="$stockItem->uom"/>
                    <x-register.fact label="Category" :value="$stockItem->category"/>
                    <x-register.fact label="Manufacturer" :value="$stockItem->manufacturer"/>
                    <x-register.fact label="Part number" :value="$stockItem->part_number" mono/>
                    <x-register.fact label="Barcode" :value="$stockItem->barcode" mono/>

                    <x-register.fact label="Reorder level">
                        @if ($stockItem->reorder_level !== null)
                            {{ rtrim(rtrim(number_format((float) $stockItem->reorder_level, 4), '0'), '.') }}
                            {{ $stockItem->uom }} — usual order
                            {{ rtrim(rtrim(number_format((float) $stockItem->reorder_quantity, 4), '0'), '.') }}
                        @else
                            Not set
                        @endif
                    </x-register.fact>

                    @if ($canSeeValue)
                        <x-register.fact label="Last purchase price"
                                         :value="$stockItem->last_purchase_price !== null
                                            ? number_format((float) $stockItem->last_purchase_price, 2)
                                            : null"/>
                    @endif

                    <x-register.fact label="Storage" :value="$stockItem->storage_conditions"/>
                    <x-register.fact label="Shelf life"
                                     :value="$stockItem->shelf_life_days ? $stockItem->shelf_life_days.' days' : null"/>
                </dl>
            </div>

            @if ($stockItem->specification || $stockItem->description)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Specification</h3></div>
                    <div class="space-y-3 p-5 text-sm text-[var(--text-body)]">
                        @if ($stockItem->description)
                            <p class="whitespace-pre-line">{{ $stockItem->description }}</p>
                        @endif
                        @if ($stockItem->specification)
                            <p class="whitespace-pre-line font-mono text-xs">{{ $stockItem->specification }}</p>
                        @endif
                    </div>
                </div>
            @endif

            @if (count($transitions) || $canDelete)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Catalogue status</h3></div>
                    <div class="space-y-4 p-5">
                        @if (count($transitions))
                            <x-register.transitions :action="route('stock-items.transition', $stockItem)"
                                                    :transitions="$transitions" noun="item"/>
                        @endif

                        @if ($canDelete)
                            {{-- Discontinuing is the honest end of an item and keeps its history
                                 attached. Deleting is for a row created by mistake. --}}
                            <x-delete-form :action="route('stock-items.destroy', $stockItem)"
                                           label="Delete this item"
                                           confirm="Deleting removes the catalogue entry. Prefer discontinuing it, which keeps its movement history readable. Continue?"/>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
