{{--
    Fuel receipts.

    THERE IS NO SHOW PAGE, and that is the honest shape rather than an omission. A fill is five facts
    and a receipt number, and every one of them is on this row. A page of its own would be a back
    button with a heading above it.

    THE TOTALS DESCRIBE THE FILTER, NOT THE PAGE. They are computed on a clone of the query before
    pagination, so "this month, tipper truck" reports what that truck drank this month and not what
    fitted on page one. A figure that silently means "page one" is worse than no figure.

    THIS REGISTER OPENS ON THE CURRENT MONTH, because fuel is asked about in months — a receipt from
    March is filed, not chased.

    Expects: $logs, $totals, $vehicles, $projects, $views, $filters, $canCreate
--}}
<x-layouts.app title="Fuel">

    <x-page-header
        title="Fuel"
        description="Every fill, and nothing computed from them that is not shown alongside.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('fuel-logs.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Record a fill
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="fuel-logs.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="fuel-logs.index" :filters="$filters" :projects="$projects"
                        placeholder="Station or receipt number">
        <x-slot:extra>
            <div>
                <label for="vehicle" class="label">Vehicle</label>
                <x-select name="vehicle" :options="$vehicles" :selected="$filters['vehicle']"
                          placeholder="Any vehicle"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <div class="card mb-5">
        <dl class="grid gap-4 p-5 sm:grid-cols-3">
            <x-register.fact label="Fills" :value="number_format($totals['fills'])" mono/>
            <x-register.fact label="Litres" :value="number_format($totals['litres'], 2)" mono/>
            <x-register.fact label="Spend" :value="number_format($totals['spend'], 2)" mono/>
        </dl>
        <div class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
            For everything the filters above match, not just this page.
        </div>
    </div>

    <x-register.table :records="$logs" icon="coins"
                      title="No fuel records"
                      description="Nothing your access reaches. A fill follows the vehicle it was put into, so trucks belonging to another site may be out of view.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('fuel-logs.create') }}" class="btn btn-primary btn-sm">Record a fill</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Filled</th>
                <th>Vehicle</th>
                <th>Litres</th>
                <th>Odometer</th>
                <th>Cost</th>
                <th>Driver</th>
                <th>Project</th>
                <th></th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap text-sm">
                        {{ $log->filled_at?->format('j M Y') }}
                        @unless ($log->is_full_tank)
                            {{-- Worth a chip. A partial fill cannot be used to compute consumption,
                                 and somebody reading a column of litres should be able to see why
                                 the arithmetic skipped a row. --}}
                            <x-badge tone="neutral">Partial</x-badge>
                        @endunless
                        @if ($log->receipt_reference)
                            <span class="block font-mono text-xs text-[var(--text-muted)]">
                                {{ $log->receipt_reference }}
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($log->vehicle)
                            <a href="{{ route('vehicles.show', $log->vehicle) }}"
                               class="font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                                {{ $log->vehicle->registration_number }}
                            </a>
                        @else
                            <span class="text-sm text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="font-mono text-sm">{{ number_format((float) $log->litres, 2) }}</td>

                    <td class="whitespace-nowrap font-mono text-sm text-[var(--text-muted)]">
                        @if ($log->odometer !== null)
                            {{ number_format((float) $log->odometer) }}
                            <span class="text-xs">{{ $log->vehicle?->odometer_unit }}</span>
                        @else
                            —
                        @endif
                    </td>

                    <td class="whitespace-nowrap font-mono text-sm">
                        @if ($log->total_cost !== null)
                            {{ number_format((float) $log->total_cost, 2) }}
                            @if ($log->effectiveUnitCost() !== null)
                                <span class="block text-xs text-[var(--text-muted)]">
                                    {{ number_format($log->effectiveUnitCost(), 2) }} / L
                                </span>
                            @endif
                        @else
                            —
                        @endif
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">{{ $log->driver?->name ?: '—' }}</td>

                    <td class="text-sm text-[var(--text-muted)]">{{ $log->project?->name ?: '—' }}</td>

                    <td class="whitespace-nowrap text-right">
                        @can('update', $log)
                            <a href="{{ route('fuel-logs.edit', $log) }}" class="btn btn-ghost btn-sm">Edit</a>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
