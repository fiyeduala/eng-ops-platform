<x-layouts.app title="New document">

    <x-page-header
        title="New document"
        description="Saving raises the number and, for a native document, opens its first revision for editing. Nothing is approved and nothing is circulated until you submit it."/>

    <form method="POST" action="{{ route('documents.store') }}">
        @csrf

        @include('documents.partials.form')

        <div class="mt-6 flex items-center justify-end gap-3">
            <a href="{{ route('documents.index') }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Create document
            </button>
        </div>
    </form>

</x-layouts.app>
