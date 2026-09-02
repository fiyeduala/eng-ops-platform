{{--
    Correct a purchase order.

    WHAT IS NOT ON THIS FORM. The number, the status, the approval, the issue and the cancellation.
    Who approved an order and when is the evidence that somebody other than the buyer authorised the
    spend, and an order whose approval could be retyped afterwards would prove nothing (§59, §84).

    WHAT ARRIVED IS NOT HERE EITHER. Received quantities come from goods receipts and nowhere else —
    a person who could type "received in full" is a person who could pay for goods that never came.

    Expects: $purchaseOrder, $quotation, $projects, $units, $people, $confidentialities, $vendors,
             $locations, $quotations, $purchaseRequests, $contracts, $nextNumber
--}}
<x-layouts.app :title="'Edit '.$purchaseOrder->number">

    <x-page-header
        :title="'Edit '.$purchaseOrder->number"
        :description="$purchaseOrder->title.' — approval, issue and deliveries move from the order\'s own page.'"/>

    <form method="POST" action="{{ route('purchase-orders.update', $purchaseOrder) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('purchase-orders.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('purchase-orders.show', $purchaseOrder) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
