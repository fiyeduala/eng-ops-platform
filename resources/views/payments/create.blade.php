<x-layouts.app title="New payment">
    <x-page-header title="New payment" description="Who is paid, the currency and the project all come from what is being paid."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('payments.store') }}" class="card p-5">
        @csrf
        @if ($payables === [])
            <p class="text-sm text-[var(--text-muted)]">There is nothing you can see that is waiting to be paid or received.</p>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                <x-field label="For" name="payable" required class="md:col-span-2">
                    @php($current = old('payable', $chosen))
                    <select name="payable" id="payable" class="select">
                        <option value="">Choose an order, claim or income</option>
                        @foreach ($payables as $group => $options)
                            <optgroup label="{{ $group }}">
                                @foreach ($options as $value => $label)
                                    <option value="{{ $value }}" @selected($current === $value)>{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </x-field>
                <x-field label="Amount" name="amount" required><x-input type="number" step="0.01" min="0.01" name="amount" :value="old('amount')"/></x-field>
                <x-field label="Method" name="method" required><x-select name="method" :options="$methods" :selected="old('method', $payment->method)"/></x-field>
                <x-field label="Reference" name="reference"><x-input name="reference" :value="old('reference')" placeholder="Bank or cheque reference"/></x-field>
                <x-field label="Date" name="paid_on" help="For money received, the day it arrived."><x-input type="date" name="paid_on" :value="old('paid_on')"/></x-field>
                <x-field label="Confidentiality" name="confidentiality" required help="Never lower than the record being paid."><x-select name="confidentiality" :options="$confidentialities" :selected="old('confidentiality', $payment->confidentiality?->value ?? $payment->confidentiality)"/></x-field>
            </div>
            <x-field label="Notes" name="notes" class="mt-4"><x-textarea name="notes" rows="2">{{ old('notes') }}</x-textarea></x-field>
            <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save payment</button><a href="{{ route('payments.index') }}" class="btn btn-ghost">Cancel</a></div>
        @endif
    </form>
</x-layouts.app>
