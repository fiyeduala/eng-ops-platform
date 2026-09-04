{{--
    One store, and what is on its shelves.

    THE BALANCE TABLE LEADS, before the particulars. Somebody opening a store page wants to know
    what is in it; the address and the storekeeper are reference material and sit in the sidebar.

    VALUE IS BEHIND `stock.view_value`. The quantity columns are shown to everybody who can see the
    store, the cost and value columns only to somebody granted them — a storekeeper counts all day
    without needing to know the margin on what they are counting (§68).

    Expects: $warehouse, $balances, $timeline, $transitions, $canEdit, $canDelete, $canSuspend,
             $canSeeValue
--}}
<x-layouts.app :title="$warehouse->name">

    <x-page-header :title="$warehouse->name" :description="$warehouse->code">
        <x-slot:actions>
            @if ($warehouse->status)
                <x-badge :tone="$warehouse->status->tone()">{{ $warehouse->status->label }}</x-badge>
            @endif

            <a href="{{ route('stock.index', ['warehouse' => $warehouse->id]) }}" class="btn btn-ghost btn-sm">
                Movements
            </a>

            @if ($canEdit)
                <a href="{{ route('warehouses.edit', $warehouse) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless ($warehouse->acceptsMovements())
        {{-- Said once, plainly, at the top. A storekeeper who discovers this by having a receipt
             refused three screens later has been let down by the page, not by the rule. --}}
        <div class="card mb-5 border-amber-300 bg-amber-50 p-4 text-sm text-amber-900
                    dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            {{ $warehouse->movementRefusalReason() ?? 'This store is not accepting movements.' }}
        </div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">On the shelf</h3>
                    <span class="text-xs text-[var(--text-muted)]">
                        {{ $balances->total() }} {{ Str::plural('item', $balances->total()) }}
                    </span>
                </div>

                @if ($balances->isEmpty())
                    <x-empty-state icon="archive" title="Nothing held here"
                                   description="No item has a balance in this store. Receiving a delivery against it, or transferring stock in, will start one."/>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-right">On hand</th>
                                    <th class="text-right">Reserved</th>
                                    <th class="text-right">Available</th>
                                    @if ($canSeeValue)
                                        <th class="text-right">Average cost</th>
                                        <th class="text-right">Value</th>
                                    @endif
                                    <th>Last moved</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($balances as $balance)
                                    <tr>
                                        <td>
                                            <a href="{{ route('stock-items.show', $balance->stock_item_id) }}"
                                               class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                                {{ $balance->stockItem?->name ?? 'Unknown item' }}
                                            </a>
                                            <span class="block font-mono text-xs text-[var(--text-muted)]">
                                                {{ $balance->stockItem?->code }}
                                            </span>
                                        </td>

                                        <td class="text-right font-mono text-sm">
                                            <span @class(['font-medium text-rose-600' => $balance->isBelowReorderLevel()])>
                                                {{ rtrim(rtrim(number_format((float) $balance->quantity_on_hand, 4), '0'), '.') }}
                                            </span>
                                            <span class="ml-1 text-xs text-[var(--text-muted)]">{{ $balance->stockItem?->uom }}</span>
                                        </td>

                                        <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                                            {{ rtrim(rtrim(number_format((float) $balance->quantity_reserved, 4), '0'), '.') }}
                                        </td>

                                        <td class="text-right font-mono text-sm">
                                            {{ rtrim(rtrim(number_format($balance->available(), 4), '0'), '.') }}
                                        </td>

                                        @if ($canSeeValue)
                                            <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                                                {{ number_format((float) $balance->average_cost, 2) }}
                                            </td>
                                            <td class="text-right font-mono text-sm">
                                                {{ number_format((float) $balance->total_value, 2) }}
                                            </td>
                                        @endif

                                        <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                                            {{ $balance->last_movement_at?->format('j M Y') ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($balances->hasPages())
                        <div class="border-t border-[var(--border)] p-4">{{ $balances->links() }}</div>
                    @endif
                @endif
            </div>

            <x-timeline :entries="$timeline"
                        empty="Nothing has happened to this store record yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title">Particulars</h3></div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="Code" :value="$warehouse->code" mono/>
                    <x-register.fact label="Kind" :value="$warehouse->type"/>
                    <x-register.fact label="Location" :value="$warehouse->location?->name"/>
                    <x-register.fact label="Storekeeper" :value="$warehouse->keeper?->name"/>
                    <x-register.fact label="Project" :value="$warehouse->project?->name ?: 'Not project-specific'"/>
                    <x-register.fact label="Owning unit" :value="$warehouse->orgUnit?->name"/>

                    <x-register.fact label="Negative stock">
                        @if ($warehouse->allow_negative_stock)
                            {{-- Worth saying in words rather than as a tick. A store that may go
                                 negative will, and anybody reading its balances should know that
                                 a minus figure there is permitted rather than a fault. --}}
                            Permitted — issues are accepted beyond what is on hand
                        @else
                            Refused — an issue cannot take a balance below zero
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Added" :value="$warehouse->created_at?->format('j M Y')"/>
                </dl>
            </div>

            @if ($warehouse->notes)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Notes</h3></div>
                    <div class="whitespace-pre-line p-5 text-sm text-[var(--text-body)]">{{ $warehouse->notes }}</div>
                </div>
            @endif

            @if (count($transitions) || $canSuspend || $canDelete)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Store status</h3></div>
                    <div class="space-y-4 p-5">
                        @if (count($transitions))
                            <x-register.transitions :action="route('warehouses.transition', $warehouse)"
                                                    :transitions="$transitions" noun="store"/>
                        @endif

                        @if ($canSuspend && $warehouse->statusKey() === 'active')
                            <form method="POST" action="{{ route('warehouses.suspend', $warehouse) }}">
                                @csrf
                                <button type="submit" class="btn btn-ghost btn-sm w-full">
                                    Suspend for a stocktake
                                </button>
                                <p class="help mt-1">
                                    Every receipt and issue is refused until it is brought back into use.
                                </p>
                            </form>
                        @endif

                        @if ($canDelete)
                            {{-- Closing is the honest end of a store; deleting one is only ever for
                                 a record created by mistake, which is why it sits last and behind
                                 its own confirmation. --}}
                            <x-delete-form :action="route('warehouses.destroy', $warehouse)"
                                           label="Delete this store"
                                           confirm="Deleting removes the store record. Its movements remain in the ledger. Continue?"/>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
