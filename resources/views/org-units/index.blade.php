{{--
    The organization tree.

    Rows are ordered by materialized path, so a flat table reads as a tree. Indentation is relative
    to `$baseDepth` — the shallowest unit this viewer can see — not to absolute depth. A department
    head whose division is invisible to them gets a tree that starts at the left margin, rather than
    one indented two levels by units they are not allowed to know exist.
--}}
<x-layouts.app title="Organization">

    <x-page-header
        title="Organization"
        description="Divisions, departments, sections and teams. This structure decides who can see whose work, so changing it changes visibility.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('org-units.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New unit
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('org-units.index') }}" class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="q" class="label">Search</label>
            <x-input name="q" :value="$filters['q']" placeholder="Name or code"/>
        </div>

        <div class="sm:w-52">
            <label for="type" class="label">Level</label>
            <x-select name="type" :options="$types" :selected="$filters['type']" placeholder="Every level"/>
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
                <a href="{{ route('org-units.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- tree --}}
    <div class="card overflow-hidden">
        @if ($units->isEmpty())
            <x-empty-state
                icon="sitemap"
                title="No units match"
                description="Either nothing matches this filter, or the structure you are allowed to see is empty. Both look the same from here.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('org-units.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Create the first unit
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Unit</th>
                            <th>Level</th>
                            <th>Head</th>
                            @if ($headcounts !== null)
                                <th class="text-right">People</th>
                            @endif
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($units as $unit)
                            @php $indent = max(0, (int) $unit->depth - $baseDepth); @endphp
                            <tr class="{{ $unit->is_active ? '' : 'opacity-60' }}">
                                <td>
                                    <div class="flex items-center" style="padding-left: {{ $indent * 1.25 }}rem">
                                        @if ($indent > 0)
                                            <x-icon name="chevron-right" class="mr-1 h-3.5 w-3.5 shrink-0 text-[var(--text-muted)]"/>
                                        @endif
                                        <a href="{{ route('org-units.show', $unit) }}"
                                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $unit->name }}</a>
                                        @unless ($unit->is_active)
                                            <x-badge tone="neutral" class="ml-2">Inactive</x-badge>
                                        @endunless
                                    </div>
                                    @if ($unit->code)
                                        <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]"
                                              style="padding-left: {{ $indent * 1.25 + ($indent > 0 ? 1.25 : 0) }}rem">{{ $unit->code }}</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $unit->type?->defaultLabel() }}</td>
                                <td>{{ $unit->head?->fullName() ?? '—' }}</td>
                                @if ($headcounts !== null)
                                    <td class="text-right tabular-nums">{{ $headcounts[$unit->id] ?? 0 }}</td>
                                @endif
                                <td class="text-right">
                                    <a href="{{ route('org-units.show', $unit) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($headcounts === null)
                <p class="border-t border-[var(--surface-border)] px-4 py-3 text-xs text-[var(--text-muted)]">
                    Headcounts are not shown because you do not have permission to see personnel records.
                </p>
            @endif
        @endif
    </div>

</x-layouts.app>
