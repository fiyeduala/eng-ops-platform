{{--
    Edit a query.

    Only ever before it has been answered — RfiPolicy refuses `update` once `answered_at` is set,
    because an answered query is a position the organization has taken in writing and a register that
    let the question be reworded afterwards would be worthless in the dispute it exists for (§59).

    The kind is shown but ignored on save. It chose the number series, and a query that changed kind
    would carry the wrong reference for the rest of its life.
--}}
<x-layouts.app :title="'Edit '.$rfi->number">

    <x-page-header
        :title="'Edit '.$rfi->number"
        :description="$rfi->subject">
        <x-slot:actions>
            <a href="{{ route('rfis.show', $rfi) }}" class="btn btn-ghost btn-sm">Back to the query</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('rfis.update', $rfi) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('rfis.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('rfis.show', $rfi) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
