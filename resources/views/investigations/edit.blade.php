{{--
    Editing an investigation.

    THERE IS NO CREATE SCREEN TO MATCH THIS ONE. Investigations are opened from an incident, so the
    only two ways in are that form and a configured workflow action.

    Expects: $investigation, $projects, $units, $people, $confidentialities, $methods
--}}
<x-layouts.app :title="'Edit '.$investigation->displayName()">

    <x-page-header :title="'Edit '.$investigation->number"
                   description="Everything recorded here can be changed until the investigation is completed.">
        <x-slot:actions>
            <a href="{{ route('investigations.show', $investigation) }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('investigations.update', $investigation) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('investigations.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the investigation</button>
            <a href="{{ route('investigations.show', $investigation) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
