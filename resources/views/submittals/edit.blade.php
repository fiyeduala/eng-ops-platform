{{--
    Edit the header of a package.

    Only the header. The contents are managed on the package's own page and the verdict is not
    editable from anywhere — a reviewed submittal is refused `update` by its policy, so this form
    is unreachable once a code has come back.
--}}
<x-layouts.app :title="'Edit '.$submittal->displayName()">

    <x-page-header
        :title="'Edit '.$submittal->title"
        :description="$submittal->number">
        <x-slot:actions>
            <a href="{{ route('submittals.show', $submittal) }}" class="btn btn-ghost btn-sm">
                Back to the package
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('submittals.update', $submittal) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('submittals.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('submittals.show', $submittal) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
