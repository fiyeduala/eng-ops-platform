{{-- Expects: $vehicle, $assetId, $assets, $types, $fuelTypes, $odometerUnits --}}
<x-layouts.app title="Attach a plate">

    <x-page-header
        title="Attach a plate"
        description="Give an asset the extra page a vehicle needs: a registration, an odometer and a fuel habit. Everything else about it stays on the asset."/>

    <form method="POST" action="{{ route('vehicles.store') }}" class="space-y-6">
        @csrf

        @include('vehicles.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Attach the plate</button>
            <a href="{{ route('vehicles.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
