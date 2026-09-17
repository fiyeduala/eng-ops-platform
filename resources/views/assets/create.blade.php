{{-- Expects: $asset, $categories, $vendors, $confidentialities --}}
<x-layouts.app title="Add an asset">

    <x-page-header
        title="Add an asset"
        description="Something the company owns. It is held by nobody until somebody takes custody of it."/>

    @isset ($receiptItem)
        <p class="mb-4 text-sm text-[var(--text-muted)]">
            Registering the item from {{ $receiptItem->goodsReceipt->number }}.
            Acquisition cost, supplier and delivery date come from the purchase record.
        </p>
    @endisset

    <form method="POST" action="{{ isset($receiptItem) ? route('assets.from-receipt.store', $receiptItem) : route('assets.store') }}" class="space-y-6">
        @csrf

        @include('assets.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the asset</button>
            <a href="{{ route('assets.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
