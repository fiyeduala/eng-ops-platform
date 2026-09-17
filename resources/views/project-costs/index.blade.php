<x-layouts.app title="Project costs">
    <x-page-header title="Project costs"
                   description="Costs incurred from accepted deliveries, in the organization's currency."/>

    <form method="GET" class="card mb-5 grid gap-4 p-4 md:grid-cols-5">
        <x-field label="Search" name="q">
            <x-input name="q" :value="$filters['q']" placeholder="Cost number or description"/>
        </x-field>
        <x-field label="Project" name="project">
            <x-select name="project" :options="$projects" :selected="$filters['project']" placeholder="All projects"/>
        </x-field>
        <x-field label="From" name="from"><x-input type="date" name="from" :value="$filters['from']"/></x-field>
        <x-field label="To" name="to"><x-input type="date" name="to" :value="$filters['to']"/></x-field>
        <div class="flex items-end gap-2">
            <button class="btn btn-primary" type="submit">Filter</button>
            <a class="btn btn-ghost" href="{{ route('project-costs.index') }}">Clear</a>
        </div>
    </form>

    @if ($baseTotals->isNotEmpty())
        <div class="mb-4 flex flex-wrap gap-3 text-sm text-[var(--text-muted)]">
            @foreach ($baseTotals as $currency => $total)
                <span class="card px-3 py-2">Filtered total: <strong class="font-mono text-[var(--text-strong)]">{{ $currency }} {{ number_format((float) $total, 2) }}</strong></span>
            @endforeach
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Date</th><th>Cost</th><th>Project</th><th>Supplier</th><th class="text-right">Original</th><th class="text-right">Base amount</th></tr></thead>
                <tbody>
                @forelse ($costs as $cost)
                    <tr>
                        <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">{{ $cost->incurred_at?->format('j M Y') }}</td>
                        <td>
                            <a href="{{ route('project-costs.show', $cost) }}" class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $cost->description }}</a>
                            <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $cost->number }}</span>
                        </td>
                        <td class="text-sm text-[var(--text-muted)]">{{ $cost->project?->name ?: 'Overhead / unassigned' }}</td>
                        <td class="text-sm text-[var(--text-muted)]">{{ $cost->vendor?->name ?: '—' }}</td>
                        <td class="whitespace-nowrap text-right font-mono text-sm">{{ $cost->currency }} {{ number_format((float) $cost->amount, 2) }}</td>
                        <td class="whitespace-nowrap text-right font-mono text-sm font-medium">{{ $cost->base_currency }} {{ number_format((float) $cost->base_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-10 text-center text-sm text-[var(--text-muted)]">No project costs match these filters.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($costs->hasPages())<div class="border-t border-[var(--surface-border)] p-4">{{ $costs->links() }}</div>@endif
    </div>
</x-layouts.app>
