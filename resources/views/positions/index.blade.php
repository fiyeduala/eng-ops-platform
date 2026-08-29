{{--
    Job titles.

    Reference data, but not global reference data: a position belongs to an org unit, so it obeys
    the same scope rules as everything else. A department administrator maintains their own titles
    and never learns what other departments call theirs.
--}}
<x-layouts.app title="Positions">

    <x-page-header
        title="Positions"
        description="Job titles, each belonging to a unit. A title is what a person holds; the unit is where they hold it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('positions.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New position
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form method="GET" action="{{ route('positions.index') }}" class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="q" class="label">Search</label>
            <x-input name="q" :value="$filters['q']" placeholder="Title or code"/>
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
            @if ($filters['q'] || $filters['include_inactive'])
                <a href="{{ route('positions.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    <div class="card overflow-hidden">
        @if ($positions->isEmpty())
            <x-empty-state
                icon="badge"
                title="No positions"
                description="Nothing matches this filter, or no job titles have been defined for the units you can see.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('positions.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Create a position
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Unit</th>
                            <th>Grade</th>
                            <th class="text-right">Holders</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($positions as $position)
                            <tr class="{{ $position->is_active ? '' : 'opacity-60' }}">
                                <td>
                                    <span class="font-medium text-[var(--text-strong)]">{{ $position->title }}</span>
                                    @if ($position->is_management)
                                        <x-badge tone="brand" class="ml-2">Management</x-badge>
                                    @endif
                                    @unless ($position->is_active)
                                        <x-badge tone="neutral" class="ml-2">Inactive</x-badge>
                                    @endunless
                                    @if ($position->code)
                                        <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $position->code }}</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $position->orgUnit?->name ?? '—' }}</td>
                                <td class="text-[var(--text-muted)]">{{ $position->grade ?: '—' }}</td>
                                <td class="text-right tabular-nums">{{ $position->employees_count }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @can('update', $position)
                                            <a href="{{ route('positions.edit', $position) }}" class="btn btn-sm btn-ghost">
                                                <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                                            </a>
                                        @endcan
                                        @can('delete', $position)
                                            <x-delete-form :action="route('positions.destroy', $position)"
                                                           label="Delete"
                                                           confirm="Delete {{ $position->title }}?"/>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($positions->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">{{ $positions->links() }}</div>
            @endif
        @endif
    </div>

</x-layouts.app>
