{{-- Expects: $warehouse, $projects, $units, $keepers, $confidentialities, $locations, $types --}}
<x-layouts.app title="Add a store">

    <x-page-header
        title="Add a store"
        description="A place material is kept. It starts as being set up and accepts no movements until it is opened."/>

    <form method="POST" action="{{ route('warehouses.store') }}" class="space-y-6">
        @csrf

        @include('warehouses.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the store</button>
            <a href="{{ route('warehouses.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
