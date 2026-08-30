<x-layouts.app title="New memo">

    <x-page-header
        title="New memo"
        description="Saving drafts it. Nothing is sent, and nobody but you sees it, until you issue it."/>

    <form method="POST" action="{{ route('memos.store') }}" class="card max-w-4xl">
        @csrf

        @include('memos.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('memos.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save draft
            </button>
        </div>
    </form>

</x-layouts.app>
