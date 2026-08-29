<x-layouts.app :title="'Edit '.$project->name">

    <x-page-header
        :title="'Edit '.$project->name"
        :description="$project->number.' · status and archiving are changed from the project page, not here.'"/>

    <form method="POST" action="{{ route('projects.update', $project) }}" class="card max-w-4xl">
        @csrf
        @method('PUT')

        @include('projects.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('projects.show', $project) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save changes
            </button>
        </div>
    </form>

</x-layouts.app>
