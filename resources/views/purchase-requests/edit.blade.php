{{--
    Correct a purchase request.

    WHAT IS NOT ON THIS FORM: the number, the status, who submitted it, who approved it and when.
    Those are the record of what happened, and a form that could retype them would make the audit
    trail a matter of opinion (§59, §84). The service holds the same list as a deny list, so this
    page and the API agree.

    EDITING AN APPROVED REQUEST IS NOT EDITING A DRAFT. The policy decides whether this page opens
    at all; where it does, remember that somebody approved a particular list of things — changing
    the lines afterwards is a new request, not a correction.

    Expects: $purchaseRequest, $projects, $units, $people, $confidentialities, $locations,
             $priorities, $nextNumber
--}}
<x-layouts.app :title="'Edit '.$purchaseRequest->number">

    <x-page-header
        :title="'Edit '.$purchaseRequest->number"
        :description="$purchaseRequest->title.' — the status and the approval history move from the request\'s own page.'"/>

    <form method="POST" action="{{ route('purchase-requests.update', $purchaseRequest) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('purchase-requests.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('purchase-requests.show', $purchaseRequest) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
