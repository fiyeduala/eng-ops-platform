<x-layouts.app title="Income">
    <x-page-header title="Income" description="What clients owe the company, from draft to received.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('incomes.create') }}" class="btn btn-primary btn-sm">Record income</a>
            </x-slot:actions>
        @endif
    </x-page-header>
    <x-register.filters route="incomes.index" :filters="$filters" :projects="$projects" :statuses="$statuses" placeholder="Number or title"/>
    <x-register.table :records="$incomes" icon="activity" title="No income" description="No income within your scope matches these filters.">
        <x-slot:head><tr><th>Income</th><th>Date</th><th>Client</th><th>Due</th><th class="text-right">Total</th><th class="text-right">Received</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($incomes as $income)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('incomes.show', $income) }}">{{ $income->title }}</a><span class="block font-mono text-xs text-[var(--text-muted)]">{{ $income->number }}</span></td>
                    <td class="whitespace-nowrap">{{ $income->income_date?->format('j M Y') }}</td>
                    <td>{{ $income->client?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap">{{ $income->due_on?->format('j M Y') ?? '—' }}</td>
                    <td class="text-right font-mono">{{ $income->currency }} {{ number_format((float) $income->total_amount, 2) }}</td>
                    <td class="text-right font-mono">{{ number_format((float) $income->received_amount, 2) }}</td>
                    <td>@if ($income->status)<x-badge :tone="$income->status->tone()">{{ $income->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
