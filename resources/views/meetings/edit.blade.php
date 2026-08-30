<x-layouts.app :title="'Edit '.$meeting->number">

    <x-page-header
        :title="'Edit '.$meeting->title"
        :description="$meeting->number.' · invitations and minutes are managed from the meeting page, not here.'"/>

    <form method="POST" action="{{ route('meetings.update', $meeting) }}" class="card max-w-4xl">
        @csrf
        @method('PUT')

        @include('meetings.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('meetings.show', $meeting) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save changes
            </button>
        </div>
    </form>

</x-layouts.app>
