{{--
    Adding a permit type.

    Expects: $type, $precautions
--}}
<x-layouts.app title="Add a permit type">

    <x-page-header title="Add a permit type"
                   description="What kind of permit this is, and the precautions it starts with."/>

    <form method="POST" action="{{ route('permit-types.store') }}" class="space-y-6">
        @csrf

        @include('permit_types.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Add the type</button>
            <a href="{{ route('permit-types.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
