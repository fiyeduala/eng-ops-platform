{{--
    A new approval route — the shell only. Stages come next, on the builder.

    Expects: $workflow, $entities
--}}
<x-layouts.app title="New approval route">

    <x-page-header
        title="New approval route"
        description="Name it and say what it routes. It is created as a draft, so nothing enters it until you publish."/>

    <form method="POST" action="{{ route('workflows.store') }}" class="max-w-3xl">
        @csrf

        @include('workflows.partials.form')

        <div class="mt-5 flex items-center justify-end gap-3">
            <a href="{{ route('workflows.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="plus" class="h-3.5 w-3.5"/> Create draft
            </button>
        </div>
    </form>

</x-layouts.app>
