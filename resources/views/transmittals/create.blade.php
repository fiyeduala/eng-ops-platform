{{--
    A new transmittal.

    It is created as a draft with no documents on it, and that is the intended order: raise the
    covering note, then pick the revisions from a picker filtered by the project chosen here.
--}}
<x-layouts.app title="New transmittal">

    <x-page-header
        title="New transmittal"
        description="Raise the covering note first. The revisions are added next, then it is issued.">
        <x-slot:actions>
            <a href="{{ route('transmittals.index') }}" class="btn btn-ghost btn-sm">Back to transmittals</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-strong)]">
            This will be numbered <span class="font-mono">{{ $nextNumber }}</span>.
        </p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">
            The number is taken now so the draft can be referred to while it is being assembled.
            Until it is issued, only you and the people who can issue transmittals can see it at all.
        </p>
    </div>

    <form method="POST" action="{{ route('transmittals.store') }}" class="max-w-3xl">
        @csrf

        @include('transmittals.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Create the draft</button>
            <a href="{{ route('transmittals.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
