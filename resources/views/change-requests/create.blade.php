{{--
    A new change request.

    Raised as undecided, always. Nobody creates an approved change — the approval is a separate act
    behind a separate permission, performed by somebody who is not the author.
--}}
<x-layouts.app title="New change request">

    <x-page-header
        title="Raise a change"
        description="Record what is changing and why. The decision comes afterwards, from somebody else.">
        <x-slot:actions>
            <a href="{{ route('change-requests.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-strong)]">
            This will be numbered <span class="font-mono">{{ $nextNumber }}</span>.
        </p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">
            Numbering follows your company's scheme, which an administrator can change under Settings.
        </p>
    </div>

    <form method="POST" action="{{ route('change-requests.store') }}" class="max-w-3xl">
        @csrf

        @include('change-requests.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Raise the change</button>
            <a href="{{ route('change-requests.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
