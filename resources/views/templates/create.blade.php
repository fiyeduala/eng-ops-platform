{{--
    A new template.

    Starts as A4 portrait and available, because a template nobody can pick is a draft nobody
    remembers to finish.
--}}
<x-layouts.app title="New template">

    <x-page-header
        title="New template"
        description="The letterhead, the page setup and the standard wording a document starts with.">
        <x-slot:actions>
            <a href="{{ route('templates.index') }}" class="btn btn-ghost btn-sm">Back to templates</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('templates.store') }}" class="max-w-4xl">
        @csrf

        @include('templates.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Create the template</button>
            <a href="{{ route('templates.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
