<x-layouts.app title="New announcement">

    <x-page-header
        title="New announcement"
        description="Saving drafts it. Nothing appears on anybody's board, and nothing is sent, until it is published."/>

    <form method="POST" action="{{ route('announcements.store') }}" class="card max-w-4xl">
        @csrf

        @include('announcements.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('announcements.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save draft
            </button>
        </div>
    </form>

</x-layouts.app>
