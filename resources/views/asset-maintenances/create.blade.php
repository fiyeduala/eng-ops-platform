{{-- Expects: $maintenance, $assets, $people, $vendors, $types, $confidentialities --}}
<x-layouts.app title="Raise a job card">

    <x-page-header
        title="Raise a job card"
        description="Work to be done on a machine — a scheduled service, an inspection, or a breakdown that has just happened."/>

    <form method="POST" action="{{ route('asset-maintenances.store') }}" class="space-y-6">
        @csrf

        @include('asset-maintenances.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Raise the job card</button>
            <a href="{{ route('asset-maintenances.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
