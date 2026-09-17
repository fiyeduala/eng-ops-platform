<x-layouts.app :title="$income->number">
    <x-page-header :title="$income->title" :description="$income->number">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('incomes.edit', $income) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
            @if ($canReceive)<a href="{{ route('payments.create', ['payable' => 'income:'.$income->id]) }}" class="btn btn-primary btn-sm">Record receipt</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php($status = $income->statusKey())
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-4">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Net</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $income->amount, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Tax ({{ rtrim(rtrim(number_format((float) $income->tax_rate, 4), '0'), '.') }}%)</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $income->tax_amount, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Total ({{ $income->currency }})</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $income->total_amount, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Outstanding</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $income->outstanding(), 2) }}</p></div>
            </div>
            @if ($income->description)
                <div class="card p-5"><h2 class="card-title">Description</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $income->description }}</p></div>
            @endif
            @if ($status === 'cancelled' && $income->decision_reason)
                <div class="card p-5"><h2 class="card-title">Reason for cancellation</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $income->decision_reason }}</p></div>
            @endif
            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Receipts</h2></div>
                <table class="table">
                    <thead><tr><th>Payment</th><th>Received</th><th>Method</th><th class="text-right">Amount</th></tr></thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            <tr><td><a href="{{ route('payments.show', $payment) }}" class="font-mono hover:text-brand-600">{{ $payment->number }}</a></td><td>{{ $payment->paid_on?->format('j M Y') }}</td><td>{{ Str::headline($payment->method) }}</td><td class="text-right font-mono">{{ number_format((float) $payment->amount, 2) }}</td></tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-sm text-[var(--text-muted)]">Nothing received that you can see.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this income."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$income->status?->label"/>
                    <x-register.fact label="Category" :value="$income->category"/>
                    <x-register.fact label="Date" :value="$income->income_date?->format('j M Y')"/>
                    <x-register.fact label="Due" :value="$income->due_on?->format('j M Y')"/>
                    <x-register.fact label="Client" :value="$income->client?->name"/>
                    <x-register.fact label="Contract" :value="$income->contract?->displayName()"/>
                    <x-register.fact label="Invoice reference" :value="$income->invoice_reference"/>
                    <x-register.fact label="Tax code" :value="$income->taxCode?->displayName()"/>
                    <x-register.fact label="Project" :value="$income->project?->name"/>
                    <x-register.fact label="Unit" :value="$income->orgUnit?->name"/>
                </dl>
                @if ($status === 'draft' && $canIssue)
                    <form method="POST" action="{{ route('incomes.issue', $income) }}" class="mt-5">@csrf<button class="btn btn-primary btn-sm w-full">Issue to client</button></form>
                @endif
                @if (in_array($status, ['draft', 'issued'], true) && $canManage)
                    <form method="POST" action="{{ route('incomes.cancel', $income) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                        <x-textarea name="reason" rows="2" placeholder="Why is it being cancelled?"></x-textarea>
                        <button class="btn btn-danger btn-sm w-full">Cancel income</button>
                    </form>
                @endif
            </div>
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('incomes.destroy', $income)" label="Delete draft income" confirm="Delete this draft income?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
