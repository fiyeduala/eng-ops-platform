{{--
    Correct an enquiry.

    THE INVITATIONS AND THE AWARD ARE NOT HERE. Who was invited, when they were invited, who
    declined, which quotation won and why — all of that is the record of what happened, and it moves
    from the enquiry's own page behind its own permissions. An enquiry whose invitation list could be
    retyped afterwards would prove nothing about competition (§59, §84).

    Expects: $rfq, $source, $projects, $units, $people, $confidentialities, $purchaseRequests,
             $nextNumber
--}}
<x-layouts.app :title="'Edit '.$rfq->number">

    <x-page-header
        :title="'Edit '.$rfq->number"
        :description="$rfq->title.' — invitations, issue and award move from the enquiry\'s own page.'"/>

    <form method="POST" action="{{ route('rfqs.update', $rfq) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('rfqs.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('rfqs.show', $rfq) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
