<x-layouts.app title="New location">

    <x-page-header
        title="New location"
        description="A physical place people work from or work at."/>

    <form method="POST" action="{{ route('locations.store') }}" class="card max-w-3xl">
        @csrf

        @include('locations.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('locations.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create location</button>
        </div>
    </form>

</x-layouts.app>
