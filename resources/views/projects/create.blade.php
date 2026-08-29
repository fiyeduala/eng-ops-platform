<x-layouts.app title="New project">

    <x-page-header
        title="New project"
        description="The reference number and the opening status are issued by the system when you save."/>

    <form method="POST" action="{{ route('projects.store') }}" class="card max-w-4xl">
        @csrf

        @include('projects.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('projects.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Create project
            </button>
        </div>
    </form>

</x-layouts.app>
