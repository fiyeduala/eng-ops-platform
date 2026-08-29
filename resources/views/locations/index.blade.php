{{--
    Physical places: head offices, yards, sites, warehouses.

    Coordinates and a geofence radius are recorded here so that field attendance and site reporting
    have something to check against later. Nothing enforces them yet — the fields exist so the data
    is being collected from day one rather than backfilled.
--}}
<x-layouts.app title="Locations">

    <x-page-header
        title="Locations"
        description="Offices, yards, sites and stores. People and, later, assets and work are filed against them.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('locations.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New location
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form method="GET" action="{{ route('locations.index') }}" class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="q" class="label">Search</label>
            <x-input name="q" :value="$filters['q']" placeholder="Name, code or city"/>
        </div>

        <div class="sm:w-52">
            <label for="type" class="label">Type</label>
            <x-select name="type" :options="$types" :selected="$filters['type']" placeholder="Every type"/>
        </div>

        <label class="flex items-center gap-2 pb-2.5 text-sm text-[var(--text-body)]">
            <input type="checkbox" name="include_inactive" value="1" @checked($filters['include_inactive'])
                   class="h-4 w-4 rounded border-[var(--surface-border)]">
            Include inactive
        </label>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if ($filters['q'] || $filters['type'] || $filters['include_inactive'])
                <a href="{{ route('locations.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    <div class="card overflow-hidden">
        @if ($locations->isEmpty())
            <x-empty-state
                icon="pin"
                title="No locations"
                description="Nothing matches this filter, or no locations have been recorded yet.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('locations.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Add a location
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Type</th>
                            <th>Where</th>
                            <th class="text-right">Based here</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($locations as $location)
                            <tr class="{{ $location->is_active ? '' : 'opacity-60' }}">
                                <td>
                                    <span class="font-medium text-[var(--text-strong)]">{{ $location->name }}</span>
                                    @unless ($location->is_active)
                                        <x-badge tone="neutral" class="ml-2">Inactive</x-badge>
                                    @endunless
                                    @if ($location->code)
                                        <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $location->code }}</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ ucfirst((string) $location->type) }}</td>
                                <td class="text-[var(--text-muted)]">
                                    {{ collect([$location->city, $location->state, $location->country])->filter()->implode(', ') ?: '—' }}
                                    @if ($location->latitude !== null && $location->longitude !== null)
                                        <span class="mt-0.5 block font-mono text-xs">
                                            {{ number_format($location->latitude, 5) }}, {{ number_format($location->longitude, 5) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right tabular-nums">{{ $location->employees_count }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('update', $location)
                                            <a href="{{ route('locations.edit', $location) }}" class="btn btn-sm btn-ghost">
                                                <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                                            </a>
                                        @endcan
                                        @can('delete', $location)
                                            <x-delete-form :action="route('locations.destroy', $location)"
                                                           label="Delete"
                                                           confirm="Delete {{ $location->name }}?"/>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($locations->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">{{ $locations->links() }}</div>
            @endif
        @endif
    </div>

</x-layouts.app>
