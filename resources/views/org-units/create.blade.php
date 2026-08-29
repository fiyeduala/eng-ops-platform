<x-layouts.app title="New organization unit">

    <x-page-header
        title="New organization unit"
        description="Adding a unit changes the shape of the company. Everyone placed inside it inherits the visibility that shape implies."/>

    <form method="POST" action="{{ route('org-units.store') }}" class="card max-w-3xl">
        @csrf

        @include('org-units.partials.form', ['canReorganize' => true])

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('org-units.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create unit</button>
        </div>
    </form>

</x-layouts.app>
