{{--
    A new register.

    Defaults are chosen to be the least surprising thing rather than the most permissive: letters for
    revisions, internal confidentiality, written-here documents, open for business. An administrator
    who changes nothing gets a register that behaves sensibly.
--}}
<x-layouts.app title="New register">

    <x-page-header
        title="New register"
        description="How a class of documents will be numbered, revised, approved and controlled.">
        <x-slot:actions>
            <a href="{{ route('document-categories.index') }}" class="btn btn-ghost btn-sm">Back to registers</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('document-categories.store') }}" class="max-w-3xl">
        @csrf

        @include('document_categories.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Create the register</button>
            <a href="{{ route('document-categories.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
