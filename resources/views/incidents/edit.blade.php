{{--
    Amend an incident.

    Correcting the wording is not the same act as investigating it, flagging it to a regulator or
    closing it out — each of those has its own endpoint behind its own permission, and none of them
    is on this form.
--}}
<x-layouts.app :title="'Edit '.$incident->displayName()">

    <x-page-header :title="'Edit '.$incident->title" :description="$incident->number">
        <x-slot:actions>
            <a href="{{ route('incidents.show', $incident) }}" class="btn btn-ghost btn-sm">
                Back to the incident
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('incidents.update', $incident) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('incidents.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('incidents.show', $incident) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
