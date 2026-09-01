{{--
    Editing an observation.

    UNTICKING "ANONYMOUS" HERE DOES NOT REVEAL A NAME, because there was never one to reveal — the
    service wrote no `observed_by` when the card was filed. It only stops the card being labelled
    anonymous from now on.

    Expects: $observation, $projects, $units, $confidentialities, $types, $categories, $riskLevels
--}}
<x-layouts.app :title="'Edit '.$observation->displayName()">

    <x-page-header :title="'Edit '.$observation->number"
                   description="Correcting what the card says is not the same as closing it out.">
        <x-slot:actions>
            <a href="{{ route('hse-observations.show', $observation) }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('hse-observations.update', $observation) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('hse-observations.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the card</button>
            <a href="{{ route('hse-observations.show', $observation) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
