{{--
    Add a stage to a draft route.

    Reachable only while the route is a draft — the policy delegates to the parent, and the builder
    hides the button once a route is published. Somebody who follows a stale link gets a 403 rather
    than a stage silently appended to a route documents are already travelling.
--}}
<x-layouts.app :title="'Add a stage — '.$workflow->name">

    <x-page-header
        :title="'Add a stage to '.$workflow->name"
        description="It joins the end of the route. Reorder it afterwards on the builder.">
        <x-slot:actions>
            <a href="{{ route('workflows.show', $workflow) }}" class="btn btn-ghost btn-sm">Back to the route</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('workflows.stages.store', $workflow) }}" class="max-w-3xl">
        @csrf

        @include('workflows.stages.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Add the stage</button>
            <a href="{{ route('workflows.show', $workflow) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
