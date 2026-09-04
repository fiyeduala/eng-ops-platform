{{--
    Correcting a catalogue line.

    THE UNIT OF MEASURE MAY REFUSE TO CHANGE, and the refusal arrives on the field itself rather
    than as a banner: once stock has moved in metres, calling it rolls would re-base every balance
    ever posted against it. StockItemService decides, the controller puts the answer on `uom`, and
    the form partial shows it where the person is already looking.

    Expects: $stockItem, $categories, $confidentialities
--}}
<x-layouts.app :title="'Edit '.$stockItem->name">

    <x-page-header :title="'Edit '.$stockItem->name" :description="$stockItem->code"/>

    <form method="POST" action="{{ route('stock-items.update', $stockItem) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('stock-items.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('stock-items.show', $stockItem) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
