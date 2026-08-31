{{--
    A new deliverable.

    Created as an open promise with no progress and no issue date. Both are reported afterwards,
    through routes that ask a different question of the policy — because typing "this is 90% done"
    on a line you are creating is not reporting, it is asserting.
--}}
<x-layouts.app title="New deliverable">

    <x-page-header
        title="New deliverable"
        description="Record what has been promised and by when. Progress is reported against it later.">
        <x-slot:actions>
            <a href="{{ route('deliverables.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-strong)]">
            This will be numbered <span class="font-mono">{{ $nextNumber }}</span>.
        </p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">
            Numbering follows your company's scheme, which an administrator can change under
            Settings. The number is assigned when the line is saved, not now, so two people creating
            deliverables at once never collide.
        </p>
    </div>

    <form method="POST" action="{{ route('deliverables.store') }}" class="max-w-3xl">
        @csrf

        @include('deliverables.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Add to the register</button>
            <a href="{{ route('deliverables.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
