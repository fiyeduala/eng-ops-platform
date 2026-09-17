<x-layouts.app :title="$payment->number">
    <x-page-header :title="$payment->number" :description="(\App\Models\Payment::DIRECTIONS[$payment->direction] ?? '').' payment'">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('payments.edit', $payment) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php($status = $payment->statusKey())
    @php($payableRoute = ['purchase_order' => 'purchase-orders.show', 'expense' => 'expenses.show', 'income' => 'incomes.show'][$payment->payable_type] ?? null)
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Amount</p><p class="mt-1 font-mono text-xl font-semibold">{{ $payment->currency }} {{ number_format((float) $payment->amount, 2) }}</p></div>
                <div class="card p-4">
                    <p class="text-xs text-[var(--text-muted)]">For</p>
                    <p class="mt-1 text-sm font-medium">
                        @if ($payableVisible && $payableRoute)
                            <a href="{{ route($payableRoute, $payable) }}" class="hover:text-brand-600">{{ $payable->displayName() }}</a>
                        @else
                            {{ Str::headline($payment->payable_type) }} (not visible to you)
                        @endif
                    </p>
                </div>
            </div>
            @if ($payment->decision_reason && in_array($status, ['rejected', 'cancelled'], true))
                <div class="card p-5"><h2 class="card-title">Reason</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $payment->decision_reason }}</p></div>
            @endif
            @if ($payment->notes)
                <div class="card p-5"><h2 class="card-title">Notes</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $payment->notes }}</p></div>
            @endif
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this payment."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$payment->status?->label"/>
                    <x-register.fact :label="$payment->isOutgoing() ? 'Paid to' : 'Received from'" :value="$payment->payee_name"/>
                    <x-register.fact label="Method" :value="Str::headline($payment->method)"/>
                    <x-register.fact label="Reference" :value="$payment->reference"/>
                    <x-register.fact label="Date" :value="$payment->paid_on?->format('j M Y')"/>
                    <x-register.fact label="Project" :value="$payment->project?->name"/>
                    <x-register.fact label="Unit" :value="$payment->orgUnit?->name"/>
                    <x-register.fact label="Approved by" :value="$payment->approver?->name"/>
                    <x-register.fact :label="$payment->isOutgoing() ? 'Released by' : 'Recorded by'" :value="$payment->payer?->name"/>
                </dl>

                @if ($payment->isOutgoing())
                    @if ($status === 'draft' && $canEdit)
                        <form method="POST" action="{{ route('payments.submit', $payment) }}" class="mt-5">@csrf<button class="btn btn-primary btn-sm w-full">Submit for approval</button></form>
                    @endif
                    @if ($status === 'submitted' && $canApprove)
                        <div class="mt-5 space-y-3 border-t border-[var(--border)] pt-4">
                            <form method="POST" action="{{ route('payments.approve', $payment) }}">@csrf<button class="btn btn-primary btn-sm w-full">Approve payment</button></form>
                            <form method="POST" action="{{ route('payments.reject', $payment) }}" class="space-y-2">@csrf
                                <x-textarea name="reason" rows="2" placeholder="Reason for rejecting"></x-textarea>
                                <button class="btn btn-danger btn-sm w-full">Reject</button>
                            </form>
                        </div>
                    @endif
                    @if ($status === 'approved' && $canPay)
                        <form method="POST" action="{{ route('payments.pay', $payment) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                            <x-input type="date" name="paid_on" :value="now()->toDateString()"/>
                            <x-input name="reference" :value="$payment->reference" placeholder="Bank reference"/>
                            <button class="btn btn-primary btn-sm w-full">Mark as paid</button>
                        </form>
                    @endif
                    @if (in_array($status, ['draft', 'approved'], true) && $canManage)
                        <form method="POST" action="{{ route('payments.cancel', $payment) }}" class="mt-5 space-y-2 border-t border-[var(--border)] pt-4">@csrf
                            <x-textarea name="reason" rows="2" placeholder="Why is it being cancelled?"></x-textarea>
                            <button class="btn btn-secondary btn-sm w-full">Cancel payment</button>
                        </form>
                    @endif
                @endif
            </div>
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('payments.destroy', $payment)" label="Delete draft payment" confirm="Delete this draft payment?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
