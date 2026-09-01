{{--
    Requesting a permit.

    THERE IS NO "SUBMIT" STEP. Raising the permit IS the request — a draft state between typing it
    and asking for it would only produce permits nobody ever sent, on work that happened anyway.

    Expects: $permit, $projects, $units, $people, $confidentialities, $permitTypes, $nextNumber
--}}
<x-layouts.app title="Request a permit">

    <x-page-header title="Request a permit to work"
                   description="Describe the work and the window. Somebody else issues it."/>

    @if ($nextNumber)
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            It will be numbered <span class="font-mono text-[var(--text-body)]">{{ $nextNumber }}</span>.
        </p>
    @endif

    <form method="POST" action="{{ route('permits.store') }}" class="space-y-6">
        @csrf

        @include('permits.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Raise the request</button>
            <a href="{{ route('permits.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
