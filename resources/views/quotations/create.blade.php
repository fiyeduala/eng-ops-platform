{{--
    Record a quotation.

    Expects: $quotation, $rfq, $vendors, $rfqs, $documents, $confidentialities, $nextNumber
--}}
<x-layouts.app title="Record a quotation">

    <x-page-header
        title="Record a quotation"
        :description="$rfq
            ? 'Against '.$rfq->number.' — '.$rfq->title.'. Will be filed as '.$nextNumber.'.'
            : 'Will be filed as '.$nextNumber.'. Totals are worked out from the lines you enter.'"/>

    <form method="POST" action="{{ route('quotations.store') }}" class="space-y-6">
        @csrf

        @include('quotations.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the quotation</button>
            <a href="{{ $rfq ? route('rfqs.show', $rfq) : route('quotations.index') }}"
               class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
