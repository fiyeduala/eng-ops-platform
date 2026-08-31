{{--
    A new package.

    It is created EMPTY and as a draft. The contents go on next, one line at a time, and only then
    can it be submitted — the service refuses to submit a package with nothing in it, because a
    submittal with no contents is a date with no question attached.
--}}
<x-layouts.app title="New submittal">

    <x-page-header
        title="New submittal"
        description="Open the package. You will add its contents on the next screen, then submit it for review.">
        <x-slot:actions>
            <a href="{{ route('submittals.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-strong)]">
            This will be numbered <span class="font-mono">{{ $nextNumber }}</span>.
        </p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">
            A resubmittal later gets a number of its own and points back at this one, so the number
            of attempts against an item stays readable years afterwards.
        </p>
    </div>

    <form method="POST" action="{{ route('submittals.store') }}" class="max-w-3xl">
        @csrf

        @include('submittals.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Open the package</button>
            <a href="{{ route('submittals.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
