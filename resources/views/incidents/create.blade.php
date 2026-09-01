{{--
    Report an incident.

    NO DRAFT. Pressing the button reports it — there is no saving this somewhere private and coming
    back to it, because an incident sitting in one person's drafts is an incident nobody has been
    told about. Anything still unknown can be filled in afterwards.
--}}
<x-layouts.app title="Report an incident">

    <x-page-header
        title="Report an incident"
        description="File it now with what you know. Detail can be added afterwards; the report itself cannot wait for it.">
        <x-slot:actions>
            <a href="{{ route('incidents.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-strong)]">
            This will be numbered <span class="font-mono">{{ $nextNumber }}</span>.
        </p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">
            Filing it notifies whoever your organization has configured to be told, and starts
            whatever workflow is attached to incidents.
        </p>
    </div>

    <form method="POST" action="{{ route('incidents.store') }}" class="max-w-3xl">
        @csrf

        @include('incidents.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Report it</button>
            <a href="{{ route('incidents.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
