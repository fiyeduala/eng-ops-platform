<x-layouts.app title="Training matrix">
    <x-page-header title="Training matrix" description="Who is required to hold what, and who is short. One row per person, one column per requirement that applies to them.">
        <x-slot:actions>
            <a href="{{ route('training-requirements.index') }}" class="btn btn-ghost btn-sm">Requirements</a>
        </x-slot:actions>
    </x-page-header>

    {{-- The reader's own reach decides the rows; this filter only narrows what they can already see. --}}
    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-64">
            <label class="label" for="unit">Department</label>
            <x-select name="unit" :options="$units" :selected="$filters['unit'] ?: null" placeholder="Everywhere I can see"/>
        </div>
        <button class="btn btn-secondary btn-sm">Apply</button>
        @if ($filters['unit'])<a href="{{ route('training-requirements.matrix') }}" class="btn btn-ghost btn-sm">Clear</a>@endif
    </form>

    @if ($requirements->isEmpty())
        <x-empty-state icon="grid" title="No requirements yet"
                       description="Nothing has been said about what any job requires, so there is nothing to be short of. Add a requirement to start the matrix."/>
    @elseif ($employees->isEmpty())
        <x-empty-state icon="users" title="Nobody to show" description="No employee within your scope matches this filter."/>
    @else
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="sticky left-0 z-10 bg-[var(--surface)] text-left">Person</th>
                        <th class="text-left">Department</th>
                        <th class="text-center">Gaps</th>
                        @foreach ($requirements as $requirement)
                            <th class="whitespace-nowrap text-left">
                                {{ $requirement->course?->name ?? '—' }}
                                @unless ($requirement->is_mandatory)
                                    <span class="block text-[11px] font-normal text-[var(--text-muted)]">optional</span>
                                @endunless
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $cells = collect($row['cells'])->keyBy(fn ($c) => $c['requirement']->id); @endphp
                        <tr>
                            <td class="sticky left-0 z-10 bg-[var(--surface)] font-medium text-[var(--text-strong)]">
                                {{ $row['employee']->fullName() }}
                                <span class="block text-[11px] font-normal text-[var(--text-muted)]">{{ $row['employee']->position?->title ?? '—' }}</span>
                            </td>
                            <td>{{ $row['employee']->orgUnit?->name ?? '—' }}</td>
                            <td class="text-center">
                                @if ($row['gaps'] > 0)
                                    <x-badge tone="danger">{{ $row['gaps'] }}</x-badge>
                                @else
                                    <span class="text-[var(--text-muted)]">—</span>
                                @endif
                            </td>
                            @foreach ($requirements as $requirement)
                                <td class="whitespace-nowrap">
                                    @if ($cell = $cells->get($requirement->id))
                                        @include('training-requirements.partials.state', ['cell' => $cell])
                                    @else
                                        {{-- Not required of this person. Blank, not green: they are
                                             not compliant, the question simply was not asked. --}}
                                        <span class="text-[var(--text-muted)]">·</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $employees->links() }}</div>
    @endif
</x-layouts.app>
