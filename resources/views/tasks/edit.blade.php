<x-layouts.app :title="'Edit '.$task->number">

    <x-page-header
        :title="'Edit '.$task->title"
        :description="$task->number.' · status and assignment are changed from the task page, not here.'"/>

    <form method="POST" action="{{ route('tasks.update', $task) }}" class="card max-w-4xl">
        @csrf
        @method('PUT')

        @include('tasks.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('tasks.show', $task) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save changes
            </button>
        </div>
    </form>

</x-layouts.app>
