{{--
    Raise a purchase order.

    Expects: $purchaseOrder, $quotation, $projects, $units, $people, $confidentialities, $vendors,
             $locations, $quotations, $purchaseRequests, $contracts, $nextNumber
--}}
<x-layouts.app title="Raise a purchase order">

    <x-page-header
        title="Raise a purchase order"
        :description="$quotation
            ? 'From the award on '.$quotation->number.'. Will be raised as '.$nextNumber.', as a draft — nothing reaches the supplier until it is issued.'
            : 'Will be raised as '.$nextNumber.', as a draft. Nothing reaches the supplier until it is approved and issued.'"/>

    <form method="POST" action="{{ route('purchase-orders.store') }}" class="space-y-6">
        @csrf

        @include('purchase-orders.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the order</button>
            <a href="{{ $quotation ? route('quotations.show', $quotation) : route('purchase-orders.index') }}"
               class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
