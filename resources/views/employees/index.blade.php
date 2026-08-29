{{--
    The personnel directory.

    Every row here came through ScopeCompiler. Two people can open this page and see completely
    different lists without a single per-role branch in the controller — which is why the empty
    state below says "nobody you can see" rather than "no employees".
--}}
<x-layouts.app title="People">

    <x-page-header
        title="People"
        description="Personnel records. What you see here is what your access reaches, not the whole company.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('employees.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add person
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('employees.index') }}" class="card mb-5 p-4">
        {{-- The supervisor filter arrives from the dashboard's "direct reports" card. It has no
             control of its own, so it is carried forward rather than dropped on the next filter. --}}
        @if ($filters['supervisor'])
            <input type="hidden" name="supervisor" value="{{ $filters['supervisor'] }}">
        @endif

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Name, number or email"/>
            </div>

            <div>
                <label for="org_unit" class="label">Unit</label>
                <x-select name="org_unit" :options="$units" :selected="$filters['org_unit']" placeholder="Anywhere"/>
            </div>

            <div>
                <label for="position" class="label">Position</label>
                @if ($positions === [])
                    <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">Not available to you</p>
                @else
                    <x-select name="position" :options="$positions" :selected="$filters['position']" placeholder="Any position"/>
                @endif
            </div>

            <div>
                <label for="status" class="label">Status</label>
                <x-select name="status" :options="$statuses" :selected="$filters['status']"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if (array_filter($filters, fn ($v, $k) => $v && ! ($k === 'status' && $v === 'active'), ARRAY_FILTER_USE_BOTH))
                <a href="{{ route('employees.index') }}" class="btn btn-ghost">Clear</a>
            @endif
            @if ($filters['supervisor'])
                <span class="text-xs text-[var(--text-muted)]">Filtered to one supervisor’s direct reports.</span>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($employees->isEmpty())
            <x-empty-state
                icon="users"
                title="Nobody you can see"
                description="Either nothing matches this filter, or the people it would match are outside your access. Those look identical from here, and deliberately so.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('employees.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Add a person
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Position</th>
                            <th>Unit</th>
                            <th>Base</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($employees as $person)
                            <tr>
                                <td>
                                    <a href="{{ route('employees.show', $person) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $person->fullName() }}</a>
                                    <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $person->employee_no }}</span>
                                </td>
                                <td class="text-[var(--text-muted)]">{{ $person->position?->title ?? '—' }}</td>
                                <td class="text-[var(--text-muted)]">{{ $person->orgUnit?->name ?? '—' }}</td>
                                <td class="text-[var(--text-muted)]">{{ $person->location?->name ?? '—' }}</td>
                                <td>
                                    @php
                                        $tone = match ($person->employment_status) {
                                            'active' => 'success',
                                            'probation' => 'brand',
                                            'on_leave' => 'warning',
                                            'suspended', 'exited' => 'danger',
                                            default => 'neutral',
                                        };
                                    @endphp
                                    <x-badge :tone="$tone">{{ ucwords(str_replace('_', ' ', (string) $person->employment_status)) }}</x-badge>
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('employees.show', $person) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-[var(--text-muted)]">
                    Showing {{ $employees->firstItem() }}–{{ $employees->lastItem() }} of {{ $employees->total() }} you can see.
                </p>
                @if ($employees->hasPages())
                    {{ $employees->links() }}
                @endif
            </div>
        @endif
    </div>

</x-layouts.app>
