{{--
    The catalogue: what the company holds, as a list of things rather than a list of quantities.

    THE QUANTITY COLUMN IS A SUM ACROSS EVERY STORE, and it is labelled that way. A single figure
    against an item that lives in four stores is useful for "have we got any" and useless for "where
    is it" — the item's own page breaks it down, and this column exists to get people there.

    "BELOW THE REORDER LEVEL" EXCLUDES ITEMS WITH NO LEVEL rather than treating them as zero.
    Nobody has decided what low looks like for those, and an alert list built on a guess is one
    people learn to ignore.

    Expects: $stockItems, $statuses, $categories, $views, $filters, $canCreate
--}}
<x-layouts.app title="Stock catalogue">

    <x-page-header
        title="Stock catalogue"
        description="Every item the company keeps, with the unit it is counted in.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('stock-items.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add an item
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="stock-items.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="stock-items.index" :filters="$filters" :statuses="$statuses"
                        placeholder="Code, name, part number">
        <x-slot:extra>
            <div>
                <label for="category" class="label">Category</label>
                <x-select name="category" :options="$categories" :selected="$filters['category']"
                          placeholder="Any category"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$stockItems" icon="archive"
                      title="Nothing in the catalogue"
                      description="No item your access reaches. The catalogue is where an item is named and given its unit before any of it can be received.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('stock-items.create') }}" class="btn btn-primary btn-sm">Add an item</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Item</th>
                <th>Category</th>
                <th>Unit</th>
                <th class="text-right">On hand, all stores</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($stockItems as $stockItem)
                <tr>
                    <td>
                        <a href="{{ route('stock-items.show', $stockItem) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $stockItem->name }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $stockItem->code }}@if ($stockItem->part_number) · {{ $stockItem->part_number }}@endif
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $categories[$stockItem->category] ?? $stockItem->category ?: '—' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">{{ $stockItem->uom }}</td>

                    <td class="text-right font-mono text-sm">
                        @if ($stockItem->is_stock_item)
                            @php $held = (float) ($stockItem->quantity_on_hand ?? 0); @endphp
                            <span @class([
                                'font-medium text-rose-600' => $stockItem->reorder_level !== null
                                    && $held <= (float) $stockItem->reorder_level,
                            ])>
                                {{ rtrim(rtrim(number_format($held, 4), '0'), '.') }}
                            </span>
                        @else
                            {{-- Bought straight to the job and never shelved. A zero here would
                                 read as "we have run out" rather than "we do not stock this". --}}
                            <span class="text-xs text-[var(--text-muted)]">Not stocked</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($stockItem->status)
                            <x-badge :tone="$stockItem->status->tone()">{{ $stockItem->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
