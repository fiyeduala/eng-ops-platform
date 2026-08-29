<x-layouts.app :title="'Edit '.$location->name">

    <x-page-header
        :title="'Edit '.$location->name"
        description="Changing a location changes it for everyone based there."/>

    <form method="POST" action="{{ route('locations.update', $location) }}" class="card max-w-3xl">
        @csrf
        @method('PUT')

        @include('locations.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('locations.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>

</x-layouts.app>
