<x-layouts.app title="New meeting">

    <x-page-header
        title="New meeting"
        description="The reference number and the opening status are issued by the system when you save. Everybody you invite is notified straight away."/>

    <form method="POST" action="{{ route('meetings.store') }}" class="card max-w-4xl">
        @csrf

        @include('meetings.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('meetings.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Schedule meeting
            </button>
        </div>
    </form>

</x-layouts.app>
