{{--
    Editing the document's IDENTITY — its title, placement, ownership and classification — which is a
    different act from editing its CONTENT. Content lives in a revision and is edited from there, so
    that an approved revision can never be altered by someone with rights over the register entry.
--}}
<x-layouts.app :title="'Edit '.$document->displayName()">

    <x-page-header
        :title="'Edit '.$document->title"
        :description="($document->number ?? 'Unnumbered').' — this changes what the document IS, not what it says. The text lives in its revisions.'">
        <x-slot:actions>
            <a href="{{ route('documents.show', $document) }}" class="btn btn-sm btn-ghost">Back to document</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('documents.update', $document) }}">
        @csrf
        @method('PUT')

        @include('documents.partials.form')

        <div class="mt-6 flex items-center justify-end gap-3">
            <a href="{{ route('documents.show', $document) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save changes
            </button>
        </div>
    </form>

</x-layouts.app>
