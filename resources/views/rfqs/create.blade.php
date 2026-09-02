{{--
    Raise an enquiry.

    Expects: $rfq, $source, $projects, $units, $people, $confidentialities, $purchaseRequests,
             $nextNumber
--}}
<x-layouts.app title="Raise an enquiry">

    <x-page-header
        title="Raise an enquiry"
        :description="'Will be raised as '.$nextNumber.'. Nothing is sent to anybody until you issue it.'"/>

    <form method="POST" action="{{ route('rfqs.store') }}" class="space-y-6">
        @csrf

        @include('rfqs.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the enquiry</button>
            <a href="{{ route('rfqs.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
