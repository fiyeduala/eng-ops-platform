{{--
    Correct a vendor's particulars.

    THE BANK DETAILS ARE NOT ON THIS PAGE and no amount of scrolling will find them. That is the
    single most important thing about this screen: the edit form everybody uses cannot move where the
    money goes. Neither can it blacklist, un-blacklist, or invent a performance score.

    Expects: $vendor, $units, $confidentialities, $types, $categories, $nextNumber
--}}
<x-layouts.app :title="'Edit '.$vendor->name">

    <x-page-header
        :title="'Edit '.$vendor->name"
        :description="$vendor->number.' — particulars and commercial terms. Bank details, standing and assessments are changed from the vendor\'s own page.'"/>

    <form method="POST" action="{{ route('vendors.update', $vendor) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('vendors.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('vendors.show', $vendor) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
