{{--
    Edit a drawing's title block.

    The revision field is here for correcting a typo in it. Actually MOVING to a new revision is a
    different act with a different form, on the drawing's own page, because it has to be recorded —
    "Rev C was issued for construction in March" stops being true the moment Rev D silently replaces
    it, and the register would then have no answer at all (§59).
--}}
<x-layouts.app :title="'Edit '.$drawing->number">

    <x-page-header
        :title="'Edit '.$drawing->number"
        :description="$drawing->title">
        <x-slot:actions>
            <a href="{{ route('drawings.show', $drawing) }}" class="btn btn-ghost btn-sm">Back to the drawing</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('drawings.update', $drawing) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('drawings.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('drawings.show', $drawing) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
