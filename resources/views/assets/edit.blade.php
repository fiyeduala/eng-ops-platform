{{-- Expects: $asset, $categories, $vendors, $confidentialities --}}
<x-layouts.app :title="'Edit '.$asset->name">

    <x-page-header :title="'Edit '.$asset->name" :description="$asset->code"/>

    <form method="POST" action="{{ route('assets.update', $asset) }}" class="space-y-6">
        @csrf
        @method('PUT')

        @include('assets.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('assets.show', $asset) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
