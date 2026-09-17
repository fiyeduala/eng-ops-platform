{{-- Expects: $vehicle, $assetId, $assets, $types, $fuelTypes, $odometerUnits --}}
<x-layouts.app :title="'Edit '.$vehicle->registration_number">

    <x-page-header :title="'Edit '.$vehicle->registration_number"
                   :description="$vehicle->asset?->name"/>

    <form method="POST" action="{{ route('vehicles.update', $vehicle) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('vehicles.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('vehicles.show', $vehicle) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
