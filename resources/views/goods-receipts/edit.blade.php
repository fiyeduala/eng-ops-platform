{{--
    Correct a delivery record.

    WHAT IS NOT ON THIS FORM: the number, the status, the receiver and the time of receipt, the
    inspector and the time of inspection. Those are set by the acts on the receipt page — confirming,
    inspecting, accepting, disputing — and a form that could type them would let one person produce a
    receipt that reads as though somebody else checked it (§84).

    THE ORDER CANNOT BE CHANGED. It is rendered read-only in the partial: the lines on this receipt
    point at that order's items, and moving the receipt would leave them pointing at another order's.
    A delivery booked against the wrong order is deleted and entered again.

    Expects: $goodsReceipt, $order, $orders, $locations, $conditions, $confidentialities, $nextNumber
--}}
<x-layouts.app :title="'Edit '.$goodsReceipt->number">

    <x-page-header
        :title="'Edit '.$goodsReceipt->number"
        description="Correct what was counted. Who received it, and when, is recorded by the act rather than typed."/>

    <form method="POST" action="{{ route('goods-receipts.update', $goodsReceipt) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('goods-receipts.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('goods-receipts.show', $goodsReceipt) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
