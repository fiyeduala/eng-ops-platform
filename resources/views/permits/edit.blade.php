{{--
    Amending a permit.

    Expects: $permit, $projects, $units, $people, $confidentialities, $permitTypes
--}}
<x-layouts.app :title="'Edit '.$permit->number">

    <x-page-header :title="'Edit '.$permit->number" :description="$permit->title">
        <x-slot:actions>
            <a href="{{ route('permits.show', $permit) }}" class="btn btn-ghost btn-sm">Back to the permit</a>
        </x-slot:actions>
    </x-page-header>

    @if ($permit->isIssued())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                This permit has already been issued. Changing what it describes after somebody signed
                for it is rarely the right move — suspend it and raise a fresh one instead.
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('permits.update', $permit) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('permits.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('permits.show', $permit) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
