<x-layouts.app title="Payments">
    <x-page-header title="Payments" description="Money out against orders and approved claims, and money in against issued income.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('payments.create') }}" class="btn btn-primary btn-sm">New payment</a>
            </x-slot:actions>
        @endif
    </x-page-header>
    <x-register.filters route="payments.index" :filters="$filters" :projects="$projects" :statuses="$statuses" placeholder="Number, reference or payee">
        <x-slot:extra>
            <div><label for="direction" class="label">Direction</label><x-select name="direction" :options="\App\Models\Payment::DIRECTIONS" :selected="$filters['direction']" placeholder="Both"/></div>
        </x-slot:extra>
    </x-register.filters>
    <x-register.table :records="$payments" icon="coins" title="No payments" description="No payment within your scope matches these filters.">
        <x-slot:head><tr><th>Payment</th><th>Direction</th><th>Payee / payer</th><th>Method</th><th class="text-right">Amount</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($payments as $payment)
                <tr>
                    <td><a class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('payments.show', $payment) }}">{{ $payment->number }}</a>@if ($payment->reference)<span class="block text-xs text-[var(--text-muted)]">{{ $payment->reference }}</span>@endif</td>
                    <td>{{ \App\Models\Payment::DIRECTIONS[$payment->direction] ?? $payment->direction }}</td>
                    <td>{{ $payment->payee_name ?? '—' }}</td>
                    <td>{{ Str::headline($payment->method) }}</td>
                    <td class="text-right font-mono">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</td>
                    <td>@if ($payment->status)<x-badge :tone="$payment->status->tone()">{{ $payment->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
