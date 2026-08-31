{{--
    Edit a draft transmittal.

    Only a draft ever reaches this screen — TransmittalPolicy refuses `update` once `issued_at` is
    set, because at that point the record is a statement about what was sent and the other party is
    holding their own copy of it. The remedy for an issued transmittal that is wrong is to cancel it
    and issue a corrected one, which leaves both facts in the history where they belong (§59).
--}}
<x-layouts.app :title="'Edit '.$transmittal->displayName()">

    <x-page-header
        :title="'Edit '.$transmittal->subject"
        description="Still a draft, so everything here can be changed.">
        <x-slot:actions>
            <a href="{{ route('transmittals.show', $transmittal) }}" class="btn btn-ghost btn-sm">Back to the transmittal</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('transmittals.update', $transmittal) }}" class="max-w-3xl">
        @csrf
        @method('PUT')

        @include('transmittals.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('transmittals.show', $transmittal) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
