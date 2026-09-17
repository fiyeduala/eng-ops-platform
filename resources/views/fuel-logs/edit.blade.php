{{-- Expects: $log, $vehicles, $projects, $drivers --}}
<x-layouts.app title="Correct a fuel record">

    <x-page-header
        title="Correct a fuel record"
        :description="$log->displayName().' — '.($log->vehicle?->registration_number ?? 'unknown vehicle')"/>

    <form method="POST" action="{{ route('fuel-logs.update', $log) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('fuel-logs.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the correction</button>
            <a href="{{ route('fuel-logs.index', ['vehicle' => $log->vehicle_id]) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

    @can('delete', $log)
        {{-- Outside the edit form, because a form inside a form is not a thing browsers agree on.

             Deleting is ordinary here, unlike the stock ledger: a fuel receipt entered against the
             wrong company or typed in twice is a data-entry mistake, not a movement of value that
             other records depend on. It soft-deletes, and it does not wind the vehicle's odometer
             back — the truck really did cover the distance the later fills recorded. --}}
        <div class="card mt-6 p-5">
            <x-delete-form :action="route('fuel-logs.destroy', $log)"
                           label="Delete this record"
                           confirm="Deleting removes this fuel record. The vehicle's odometer is not wound back. Continue?"/>
        </div>
    @endcan

</x-layouts.app>
