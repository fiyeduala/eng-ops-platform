{{--
    Edit a route's particulars.

    Whether pressing Save edits this version or forks the next one is not this form's decision — it
    is WorkflowDefinitionService's, and `$willFork` is how the form tells the user which is about to
    happen. A user who thinks they changed a live route and actually created an unpublished version 2
    would spend a fortnight wondering why nothing changed.

    Expects: $workflow, $entities, $willFork
--}}
<x-layouts.app :title="'Edit '.$workflow->name">

    <x-page-header
        :title="'Edit '.$workflow->name"
        :description="$workflow->displayName().' · '.$workflow->statusLabel()">
        <x-slot:actions>
            <a href="{{ route('workflows.show', $workflow) }}" class="btn btn-sm btn-ghost">Back to the builder</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('workflows.update', $workflow) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('workflows.partials.form')

        <div class="mt-5 flex items-center justify-end gap-3">
            <a href="{{ route('workflows.show', $workflow) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                {{ $willFork ? 'Save as version '.($workflow->version + 1) : 'Save changes' }}
            </button>
        </div>
    </form>

</x-layouts.app>
