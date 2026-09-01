{{--
    Editing a talk.

    THE ATTENDANCE SHEET IS NOT REACHABLE FROM HERE. It is recorded on the talk's own page and only
    by somebody holding `record` — editing the briefing text and signing people in are different
    acts with different consequences.

    Expects: $talk, $projects, $units, $people, $confidentialities, $handouts
--}}
<x-layouts.app :title="'Edit '.$talk->displayName()">

    <x-page-header :title="'Edit '.$talk->number" :description="$talk->title">
        <x-slot:actions>
            <a href="{{ route('toolbox-talks.show', $talk) }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('toolbox-talks.update', $talk) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('toolbox-talks.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the talk</button>
            <a href="{{ route('toolbox-talks.show', $talk) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
