{{--
    Edit a register.

    Every field here changes the behaviour of documents that already exist, which is why the form
    warns about the revision scheme rather than silently accepting a change that would leave a
    revision history written half in letters and half in numbers.
--}}
<x-layouts.app :title="'Edit '.$category->name">

    <x-page-header
        :title="'Edit '.$category->name"
        description="Changes apply to documents raised from now on. Nothing already filed is renumbered or relabelled.">
        <x-slot:actions>
            <a href="{{ route('document-categories.show', $category) }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('document-categories.update', $category) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('document_categories.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('document-categories.show', $category) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
