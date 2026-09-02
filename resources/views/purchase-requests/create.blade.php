{{--
    Raise a purchase request.

    SAVING IT DOES NOT ASK ANYBODY FOR ANYTHING. It is a draft until it is submitted, and submitting
    is a separate act on the request's own page behind `purchase_request.submit`. That is not
    ceremony: half the requisitions in any company are typed over two days, and a form that asked
    for approval the moment you pressed save would teach everybody to write them somewhere else.

    Expects: $purchaseRequest, $projects, $units, $people, $confidentialities, $locations,
             $priorities, $nextNumber
--}}
<x-layouts.app title="Raise a purchase request">

    <x-page-header
        title="Raise a purchase request"
        :description="'Will be raised as '.$nextNumber.'. It stays a draft until you submit it.'"/>

    <form method="POST" action="{{ route('purchase-requests.store') }}" class="space-y-6">
        @csrf

        @include('purchase-requests.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the request</button>
            <a href="{{ route('purchase-requests.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
