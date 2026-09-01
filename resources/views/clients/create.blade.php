{{--
    Add a client to the register.

    Expects: $client, $units, $confidentialities, $types, $industries, $nextNumber
--}}
<x-layouts.app title="Add a client">

    <x-page-header
        title="Add a client"
        :description="'Will be registered as '.$nextNumber.'. Projects and contracts can be pointed at them as soon as they are saved.'"/>

    <form method="POST" action="{{ route('clients.store') }}" class="space-y-6">
        @csrf

        @include('clients.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Add the client</button>
            <a href="{{ route('clients.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
