{{--
    Correct a quotation.

    WHAT IS NOT ON THIS FORM. The number, the status, the subtotal and the total, and every mark any
    evaluator has given. The first is generated, the second moves through transitions and through
    the award, the totals are arithmetic over the lines, and the marks belong to the people who gave
    them — QuotationService writes only rows belonging to the signed-in evaluator, so nobody edits
    anybody else's opinion from here (§59, §84).

    Expects: $quotation, $rfq, $vendors, $rfqs, $documents, $confidentialities, $nextNumber
--}}
<x-layouts.app :title="'Edit '.$quotation->number">

    <x-page-header
        :title="'Edit '.$quotation->number"
        :description="($quotation->vendor?->name ?: 'This quotation').' — evaluation marks and the award move elsewhere.'"/>

    <form method="POST" action="{{ route('quotations.update', $quotation) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('quotations.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('quotations.show', $quotation) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
