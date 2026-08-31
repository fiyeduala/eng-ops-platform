{{--
    Raise a query.

    The number is taken when it is saved, and the kind chooses which series it comes from — RFIs,
    technical queries and site queries share this register but keep their own numbering, because
    engineers quote "TQ-2026-0031" and mean one specific document.
--}}
<x-layouts.app title="Raise a query">

    <x-page-header
        title="Raise a query"
        description="An RFI, technical query or site query. The answer is recorded later, by whoever is qualified to give it.">
        <x-slot:actions>
            <a href="{{ route('rfis.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('rfis.store') }}" class="max-w-3xl">
        @csrf

        @include('rfis.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Raise it</button>
            <a href="{{ route('rfis.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
