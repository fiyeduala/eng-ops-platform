{{--
    Raise a material request.

    SAVING IT ASKS THE STORE FOR NOTHING YET. It is a draft until it is submitted, and submitting is
    a separate act behind `material_request.submit` on the request's own page — same shape as a
    requisition, for the same reason: half of these are written across two shifts.

    Expects: $materialRequest, $projects, $units, $people, $confidentialities, $warehouses, $items,
             $locations, $priorities, $nextNumber
--}}
<x-layouts.app title="Raise a material request">

    <x-page-header
        title="Raise a material request"
        :description="'Will be raised as '.$nextNumber.'. It stays a draft until you submit it.'"/>

    <form method="POST" action="{{ route('material-requests.store') }}" class="space-y-6">
        @csrf

        @include('material-requests.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the request</button>
            <a href="{{ route('material-requests.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
