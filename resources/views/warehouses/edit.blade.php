{{-- Expects: $warehouse, $projects, $units, $keepers, $confidentialities, $locations, $types --}}
<x-layouts.app :title="'Edit '.$warehouse->name">

    <x-page-header :title="'Edit '.$warehouse->name" :description="$warehouse->code"/>

    <form method="POST" action="{{ route('warehouses.update', $warehouse) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('warehouses.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('warehouses.show', $warehouse) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
