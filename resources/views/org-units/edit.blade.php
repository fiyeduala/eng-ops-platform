<x-layouts.app :title="'Edit '.$unit->name">

    <x-page-header
        :title="'Edit '.$unit->name"
        description="Renaming a unit is cosmetic. Moving it is not — it moves everyone inside it, and everything they can see moves with them.">
        <x-slot:actions>
            <a href="{{ route('org-units.show', $unit) }}" class="btn btn-sm btn-ghost">Back to unit</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('org-units.update', $unit) }}" class="card max-w-3xl">
        @csrf
        @method('PUT')

        @include('org-units.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('org-units.show', $unit) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>

</x-layouts.app>
