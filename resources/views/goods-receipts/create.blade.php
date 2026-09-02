{{--
    Book in a delivery.

    THE PAGE HAS TWO SHAPES, and the outer form is one of them. Until an order is chosen the partial
    renders a chooser that is itself a GET form, so the POST wrapper must not be around it — nesting
    the two would post the wrong one. Once the order is known the page becomes the receipt.

    Expects: $goodsReceipt, $order, $orders, $locations, $conditions, $confidentialities, $nextNumber
--}}
<x-layouts.app title="Book in a delivery">

    <x-page-header
        title="Book in a delivery"
        :description="$order
            ? 'Against '.$order->number.'. Will be filed as '.$nextNumber.'.'
            : 'A delivery is always recorded against an order. Choose the order to begin.'"/>

    @if ($order)
        <form method="POST" action="{{ route('goods-receipts.store') }}" class="space-y-6">
            @csrf

            @include('goods-receipts.partials.form')

            <div class="flex items-center gap-3">
                <button type="submit" class="btn btn-primary">Save the receipt</button>
                <a href="{{ route('purchase-orders.show', $order) }}" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    @else
        @include('goods-receipts.partials.form')
    @endif

</x-layouts.app>
