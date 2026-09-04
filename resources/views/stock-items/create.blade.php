{{--
    Adding a line to the catalogue.

    THE CATALOGUE IS THE SHARED VOCABULARY, which is why this page exists separately from any store.
    A requisition line, an order line, a receipt line and a ledger row all point at the row created
    here; naming the same cable twice is how two stores end up holding the same thing under two
    balances that never reconcile.

    Expects: $stockItem, $categories, $confidentialities
--}}
<x-layouts.app title="Add a catalogue item">

    <x-page-header
        title="Add a catalogue item"
        description="A thing the company buys or holds, named once so every store, order and requisition means the same by it."/>

    <form method="POST" action="{{ route('stock-items.store') }}" class="space-y-6">
        @csrf

        @include('stock-items.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the item</button>
            <a href="{{ route('stock-items.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
