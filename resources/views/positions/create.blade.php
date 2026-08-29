<x-layouts.app title="New position">

    <x-page-header
        title="New position"
        description="A job title, filed under the unit that owns it."/>

    <form method="POST" action="{{ route('positions.store') }}" class="card max-w-3xl">
        @csrf

        @include('positions.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('positions.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create position</button>
        </div>
    </form>

</x-layouts.app>
