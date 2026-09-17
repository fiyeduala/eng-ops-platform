<x-layouts.app :title="'Edit '.$payment->number">
    <x-page-header :title="'Edit '.$payment->number" description="Only a draft payment can be changed. What it pays for is fixed."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('payments.update', $payment) }}" class="card p-5">
        @csrf @method('PUT')
        <div class="grid gap-4 md:grid-cols-2">
            <x-field label="Amount" name="amount" required><x-input type="number" step="0.01" min="0.01" name="amount" :value="old('amount', $payment->amount)"/></x-field>
            <x-field label="Method" name="method" required><x-select name="method" :options="$methods" :selected="old('method', $payment->method)"/></x-field>
            <x-field label="Reference" name="reference"><x-input name="reference" :value="old('reference', $payment->reference)"/></x-field>
            <x-field label="Date" name="paid_on"><x-input type="date" name="paid_on" :value="old('paid_on', $payment->paid_on?->format('Y-m-d'))"/></x-field>
            <x-field label="Confidentiality" name="confidentiality" required><x-select name="confidentiality" :options="$confidentialities" :selected="old('confidentiality', $payment->confidentiality?->value ?? $payment->confidentiality)"/></x-field>
        </div>
        <x-field label="Notes" name="notes" class="mt-4"><x-textarea name="notes" rows="2">{{ old('notes', $payment->notes) }}</x-textarea></x-field>
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('payments.show', $payment) }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
