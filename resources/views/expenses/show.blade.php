<x-layouts.app :title="$expense->number">
    <x-page-header :title="$expense->title" :description="$expense->number">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('expenses.edit', $expense) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
            @if ($canPay)<a href="{{ route('payments.create', ['payable' => 'expense:'.$expense->id]) }}" class="btn btn-primary btn-sm">Raise payment</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php($status = $expense->statusKey())
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-4">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Net</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $expense->amount, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Tax ({{ rtrim(rtrim(number_format((float) $expense->tax_rate, 4), '0'), '.') }}%)</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $expense->tax_amount, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Total ({{ $expense->currency }})</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $expense->total_amount, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Paid</p><p class="mt-1 font-mono text-lg font-semibold">{{ number_format((float) $expense->paid_amount, 2) }}</p></div>
            </div>
            @if ($expense->description)
                <div class="card p-5"><h2 class="card-title">Description</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $expense->description }}</p></div>
            @endif
            @if ($expense->decision_reason && in_array($status, ['draft', 'rejected'], true))
                <div class="card border-amber-300 p-5"><h2 class="card-title">{{ $status === 'rejected' ? 'Reason for rejection' : 'Returned for amendment' }}</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $expense->decision_reason }}</p></div>
            @endif
            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Payments</h2></div>
                <table class="table">
                    <thead><tr><th>Payment</th><th>Method</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            <tr><td><a href="{{ route('payments.show', $payment) }}" class="font-mono hover:text-brand-600">{{ $payment->number }}</a></td><td>{{ $payment->method }}</td><td class="text-right font-mono">{{ number_format((float) $payment->amount, 2) }}</td><td>@if ($payment->status)<x-badge :tone="$payment->status->tone()">{{ $payment->status->label }}</x-badge>@endif</td></tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-center text-sm text-[var(--text-muted)]">No payments you can see.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this claim."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$expense->status?->label"/>
                    <x-register.fact label="Category" :value="$expense->category"/>
                    <x-register.fact label="Date spent" :value="$expense->expense_date?->format('j M Y')"/>
                    <x-register.fact label="Claimant" :value="$expense->claimant?->name"/>
                    <x-register.fact label="Paid to" :value="$expense->payeeLabel()"/>
                    <x-register.fact label="Receipt" :value="$expense->receipt_reference"/>
                    <x-register.fact label="Tax code" :value="$expense->taxCode?->displayName()"/>
                    <x-register.fact label="Project" :value="$expense->project?->name"/>
                    <x-register.fact label="Unit" :value="$expense->orgUnit?->name"/>
                    <x-register.fact label="Cost center" :value="$expense->costCenter?->displayName()"/>
                    <x-register.fact label="Approved by" :value="$expense->approver?->name"/>
                    @if ($expense->projectCost)
                        <div><dt class="text-xs text-[var(--text-muted)]">Project cost</dt><dd class="mt-0.5 text-sm"><a href="{{ route('project-costs.show', $expense->projectCost) }}" class="font-mono hover:text-brand-600">{{ $expense->projectCost->number }}</a></dd></div>
                    @endif
                </dl>

                @if ($canSubmit)
                    <form method="POST" action="{{ route('expenses.submit', $expense) }}" class="mt-5">@csrf<button class="btn btn-primary btn-sm w-full">Submit for approval</button></form>
                @endif

                @if ($status === 'submitted' && ($canApprove || $canReject))
                    <div class="mt-5 space-y-3 border-t border-[var(--border)] pt-4">
                        @if ($canApprove)
                            <form method="POST" action="{{ route('expenses.approve', $expense) }}" class="space-y-2">@csrf
                                <x-input name="comment" placeholder="Comment (optional)"/>
                                <button class="btn btn-primary btn-sm w-full">Approve and post cost</button>
                            </form>
                        @endif
                        <form method="POST" class="space-y-2">@csrf
                            <x-textarea name="reason" rows="2" placeholder="Reason (required to return or reject)"></x-textarea>
                            @if ($canApprove)<button formaction="{{ route('expenses.return', $expense) }}" class="btn btn-secondary btn-sm w-full">Return for amendment</button>@endif
                            @if ($canReject)<button formaction="{{ route('expenses.reject', $expense) }}" class="btn btn-danger btn-sm w-full">Reject</button>@endif
                        </form>
                    </div>
                @endif
            </div>
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('expenses.destroy', $expense)" label="Delete draft claim" confirm="Delete this draft expense claim?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
