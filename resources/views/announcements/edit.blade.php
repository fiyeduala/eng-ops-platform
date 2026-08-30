<x-layouts.app :title="'Edit '.$announcement->number">

    <x-page-header
        :title="'Edit '.$announcement->title"
        :description="$announcement->number.' · corrections are fine on a live notice. Re-aiming one at a different audience needs the publishing permission.'"/>

    <form method="POST" action="{{ route('announcements.update', $announcement) }}" class="card max-w-4xl">
        @csrf
        @method('PUT')

        @include('announcements.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('announcements.show', $announcement) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save changes
            </button>
        </div>
    </form>

</x-layouts.app>
