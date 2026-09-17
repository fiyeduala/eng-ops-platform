{{-- Expects: $maintenance, $assets, $people, $vendors, $types, $confidentialities --}}
<x-layouts.app :title="'Edit '.$maintenance->reference">

    <x-page-header :title="'Edit '.$maintenance->reference" :description="$maintenance->title"/>

    <form method="POST" action="{{ route('asset-maintenances.update', $maintenance) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('asset-maintenances.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('asset-maintenances.show', $maintenance) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
