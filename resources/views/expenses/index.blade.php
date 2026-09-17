<x-layouts.app title="Expenses">
    <x-page-header title="Expenses" description="Claims for money spent on the company's behalf, from draft to paid.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('expenses.create') }}" class="btn btn-primary btn-sm">New expense claim</a>
            </x-slot:actions>
        @endif
    </x-page-header>
    <div class="mb-4 flex gap-2 text-sm">
        <a href="{{ route('expenses.index', ['view' => 'all']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-ghost' : 'btn-secondary' }}">All I can see</a>
        <a href="{{ route('expenses.index', ['view' => 'mine']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-secondary' : 'btn-ghost' }}">My claims</a>
    </div>
    <x-register.filters route="expenses.index" :filters="$filters" :projects="$projects" :statuses="$statuses" placeholder="Number or title"/>
    <x-register.table :records="$expenses" icon="file-text" title="No expenses" description="No expense within your scope matches these filters.">
        <x-slot:head><tr><th>Expense</th><th>Date</th><th>Claimant</th><th>Project</th><th class="text-right">Total</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($expenses as $expense)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('expenses.show', $expense) }}">{{ $expense->title }}</a><span class="block font-mono text-xs text-[var(--text-muted)]">{{ $expense->number }}</span></td>
                    <td class="whitespace-nowrap">{{ $expense->expense_date?->format('j M Y') }}</td>
                    <td>{{ $expense->claimant?->name ?? '—' }}</td>
                    <td class="text-sm text-[var(--text-muted)]">{{ $expense->project?->name ?? '—' }}</td>
                    <td class="text-right font-mono">{{ $expense->currency }} {{ number_format((float) $expense->total_amount, 2) }}</td>
                    <td>@if ($expense->status)<x-badge :tone="$expense->status->tone()">{{ $expense->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
