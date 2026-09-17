<x-layouts.app title="Budgets">
    <x-page-header title="Budgets" description="Approved allocations compared with costs already incurred.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('budgets.create') }}" class="btn btn-primary btn-sm">Add a budget</a>
            </x-slot:actions>
        @endif
    </x-page-header>
    <x-register.filters route="budgets.index" :filters="$filters" :projects="$projects" :statuses="$statuses" placeholder="Number or title">
        <x-slot:extra><div><label for="year" class="label">Fiscal year</label><x-input type="number" name="year" :value="$filters['year']"/></div></x-slot:extra>
    </x-register.filters>
    <x-register.table :records="$budgets" icon="clipboard" title="No budgets" description="No budget within your scope matches these filters.">
        <x-slot:head><tr><th>Budget</th><th>Year</th><th>Scope</th><th class="text-right">Allocation</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($budgets as $budget)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('budgets.show', $budget) }}">{{ $budget->title }}</a><span class="block font-mono text-xs text-[var(--text-muted)]">{{ $budget->number }}</span></td>
                    <td>{{ $budget->fiscal_year }}</td>
                    <td class="text-sm text-[var(--text-muted)]">{{ $budget->project?->name ?? $budget->orgUnit?->name ?? $budget->costCenter?->name ?? 'Organization-wide' }}</td>
                    <td class="text-right font-mono">{{ $budget->currency }} {{ number_format((float) $budget->total_amount, 2) }}</td>
                    <td>
                        @if ($budget->status)
                            <x-badge :tone="$budget->status->tone()">{{ $budget->status->label }}</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
