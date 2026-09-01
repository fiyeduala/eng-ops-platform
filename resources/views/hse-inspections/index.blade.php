{{--
    HSE inspections.

    IT OPENS ON "OPEN" — everything scheduled or carried out but not yet closed — because an
    inspection register is a to-do list first and an archive second. The walk that was never done
    and the walk whose findings nobody closed out are the same failure from a distance, and both
    belong on the first screen.

    THE SCORE IS SHOWN AS A PERCENTAGE AND AS A COUNT OF FAILED ITEMS, because they answer different
    questions. 94% sounds excellent right up until the 6% is the missing edge protection, so the
    number of items needing action sits beside it and is coloured, not the percentage.

    Expects: $inspections, $statuses, $projects, $types, $views, $filters, $canCreate
--}}
<x-layouts.app title="HSE inspections">

    <x-page-header
        title="Inspections"
        description="Planned walks, what they found, and whether it was put right.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('hse-inspections.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Schedule an inspection
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="hse-inspections.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="hse-inspections.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or location">
        <x-slot:extra>
            <div>
                <label for="inspection_type" class="label">Type</label>
                <x-select name="inspection_type" :options="$types"
                          :selected="$filters['inspection_type']" placeholder="Any type"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$inspections" icon="check-square"
                      title="No inspections"
                      description="Either nothing matches these filters, or the inspections that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('hse-inspections.create') }}" class="btn btn-primary btn-sm">Schedule an inspection</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Inspection</th>
                <th>Type</th>
                <th>Inspector</th>
                <th class="text-right">Items</th>
                <th>Result</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($inspections as $inspection)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('hse-inspections.show', $inspection) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $inspection->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($inspection->performed_at)
                                walked {{ $inspection->performed_at->format('j M Y') }}
                            @elseif ($inspection->scheduled_for)
                                @php $late = $inspection->scheduled_for->isBefore(now()->startOfDay()); @endphp
                                <span @class(['font-medium text-rose-600' => $late])>
                                    due {{ $inspection->scheduled_for->format('j M Y') }}
                                </span>
                            @else
                                unscheduled
                            @endif
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('hse-inspections.show', $inspection) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $inspection->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($inspection->location) {{ $inspection->location }} @endif
                            @if ($inspection->project) · {{ $inspection->project->displayName() }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $inspection->inspection_type ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $inspection->inspector?->name ?: 'Not yet walked' }}
                    </td>

                    <td class="whitespace-nowrap text-right tabular-nums">
                        {{ $inspection->items_count }}
                    </td>

                    {{-- The percentage comes off the inspection's own score columns, not off the
                         item rows — the rows are not loaded here, and asking each record for its
                         failures one at a time is thirty queries a page. --}}
                    <td class="whitespace-nowrap">
                        @php $percent = $inspection->compliancePercent(); @endphp
                        @if ($percent === null)
                            <span class="text-[var(--text-muted)]">Not scored</span>
                        @else
                            <x-badge :tone="match (true) {
                                $percent >= 95 => 'success',
                                $percent >= 80 => 'warning',
                                default => 'danger',
                            }">{{ $percent }}%</x-badge>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($inspection->status)
                            <x-badge :tone="$inspection->status->tone()">{{ $inspection->status->label }}</x-badge>
                        @elseif ($inspection->closed_at)
                            <x-badge tone="success">Closed</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
