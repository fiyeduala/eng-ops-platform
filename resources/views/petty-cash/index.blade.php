<x-layouts.app title="Petty cash">
    <x-page-header title="Petty cash" description="Site floats and cash tins, with a voucher for every note that goes in or out.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('petty-cash.create') }}" class="btn btn-primary btn-sm">Open a tin</a>
            </x-slot:actions>
        @endif
    </x-page-header>
    <form method="GET" class="card mb-5 flex gap-3 p-4">
        <x-input name="q" :value="$filters['q']" placeholder="Code or name"/>
        <button class="btn btn-secondary">Filter</button>
    </form>
    <div class="card overflow-hidden">
        <table class="table">
            <thead><tr><th>Account</th><th>Custodian</th><th>Placement</th><th class="text-right">Float</th><th class="text-right">Balance</th><th>Active</th></tr></thead>
            <tbody>
                @forelse ($accounts as $account)
                    <tr>
                        <td><a href="{{ route('petty-cash.show', $account) }}" class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $account->name }}</a><span class="block font-mono text-xs text-[var(--text-muted)]">{{ $account->code }}</span></td>
                        <td>{{ $account->custodian?->name ?? '—' }}</td>
                        <td>{{ $account->project?->name ?? $account->orgUnit?->name ?? 'Organization-wide' }}</td>
                        <td class="text-right font-mono">{{ (float) $account->float_amount > 0 ? number_format((float) $account->float_amount, 2) : 'No ceiling' }}</td>
                        <td class="text-right font-mono">{{ $account->currency }} {{ number_format((float) $account->balance, 2) }}</td>
                        <td><x-badge :tone="$account->is_active ? 'success' : 'neutral'">{{ $account->is_active ? 'Active' : 'Closed' }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-10 text-center text-sm text-[var(--text-muted)]">No petty cash account within your scope matches.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($accounts->hasPages())<div class="p-4">{{ $accounts->links() }}</div>@endif
    </div>
</x-layouts.app>
