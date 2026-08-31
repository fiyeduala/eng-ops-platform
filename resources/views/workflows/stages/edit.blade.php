{{--
    Edit a stage of a draft route.

    The stage's position is not editable here. Sequence is reordered on the builder, in one operation
    across every stage, because `workflow_stages.sequence` is uniquely indexed per route and moving
    one stage means renumbering its neighbours.
--}}
<x-layouts.app :title="'Edit '.$stage->name">

    <x-page-header
        :title="'Edit '.$stage->name"
        :description="'Stage '.$stage->sequence.' of '.$workflow->name.', version '.$workflow->version.'.'">
        <x-slot:actions>
            <a href="{{ route('workflows.show', $workflow) }}" class="btn btn-ghost btn-sm">Back to the route</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('workflows.stages.update', [$workflow, $stage]) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('workflows.stages.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the stage</button>
            <a href="{{ route('workflows.show', $workflow) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
