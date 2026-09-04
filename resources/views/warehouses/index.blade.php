{{--
    The stores. Not a dropdown — a register.

    THE BALANCE COUNT IS THE MOST USEFUL COLUMN HERE, and it is a count of LINES rather than a
    value. "How many different things are in this store" is the question a person browsing stores
    is actually asking; what those things are worth is a report, and it is gated separately.

    CLOSED AND SUSPENDED STORES STAY LISTED. History happened in them, and a register that hid
    them would leave every old movement pointing at a store that appears not to exist. The status
    chip says which are accepting movements today.

    Expects: $warehouses, $statuses, $projects, $types, $filters, $canCreate
--}}
<x-layouts.app title="Stores">

    <x-page-header
        title="Stores"
        description="Every place the company keeps material — a yard, a container, a lorry in transit.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('warehouses.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a store
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.filters route="warehouses.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Code or name">
        <x-slot:extra>
            <div>
                <label for="type" class="label">Kind of store</label>
                <x-select name="type" :options="$types" :selected="$filters['type']"
                          placeholder="Any kind"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$warehouses" icon="building"
                      title="No stores"
                      description="Nothing your access reaches. Stores follow the unit and project they belong to, so a site store may be visible only to that site.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('warehouses.create') }}" class="btn btn-primary btn-sm">Add a store</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Store</th>
                <th>Kind</th>
                <th>Project</th>
                <th>Storekeeper</th>
                <th class="text-right">Items held</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($warehouses as $warehouse)
                <tr>
                    <td>
                        <a href="{{ route('warehouses.show', $warehouse) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $warehouse->name }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $warehouse->code }}
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $types[$warehouse->type] ?? $warehouse->type ?: '—' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $warehouse->project?->name ?: 'Not project-specific' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $warehouse->keeper?->name ?: '—' }}
                    </td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                        {{ $warehouse->balances_count }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($warehouse->status)
                            <x-badge :tone="$warehouse->status->tone()">{{ $warehouse->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Not set</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
