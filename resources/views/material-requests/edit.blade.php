{{--
    Correct a material request.

    WHAT CAN BE EDITED IS DECIDED BEFORE THIS PAGE IS DRAWN, by the policy and by the status graph.
    Once a request has been approved the quantities somebody agreed to are a decision, and editing
    the lines underneath them would change what was approved without anybody approving it — so the
    edit route is refused there rather than the fields being greyed out here (§84).

    Expects: $materialRequest, $projects, $units, $people, $confidentialities, $warehouses, $items,
             $locations, $priorities
--}}
<x-layouts.app :title="'Edit '.$materialRequest->number">

    <x-page-header
        :title="'Edit '.$materialRequest->number"
        :description="$materialRequest->title"/>

    <form method="POST" action="{{ route('material-requests.update', $materialRequest) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('material-requests.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the changes</button>
            <a href="{{ route('material-requests.show', $materialRequest) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
