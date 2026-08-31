{{--
    Edit a deliverable.

    The promised date is editable here and that is correct — schedules are renegotiated, and a
    register that could not record a formally agreed new date would be lying about the commitment.
    What the form does NOT do is move it quietly: the change is written to the timeline with who
    made it, so "it was always due in May" can be checked rather than argued (§59).
--}}
<x-layouts.app :title="'Edit '.$deliverable->displayName()">

    <x-page-header
        :title="'Edit '.$deliverable->title"
        :description="$deliverable->number">
        <x-slot:actions>
            <a href="{{ route('deliverables.show', $deliverable) }}" class="btn btn-ghost btn-sm">
                Back to the deliverable
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('deliverables.update', $deliverable) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('deliverables.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('deliverables.show', $deliverable) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
