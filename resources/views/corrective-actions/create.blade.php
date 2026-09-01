{{--
    Raising a standalone action.

    MOST ACTIONS ARE NOT RAISED HERE. They come off an incident, an investigation, an observation, an
    inspection or an NCR, and they carry that record's project and department with them. This screen
    is for the ones that come out of a meeting or an audit finding with no record behind them yet.

    Expects: $action, $projects, $units, $people, $confidentialities, $types, $priorities, $nextNumber
--}}
<x-layouts.app title="Raise a corrective action">

    <x-page-header title="Raise a corrective action"
                   description="Something that has to be done, by somebody, by a date.">
        <x-slot:actions>
            <a href="{{ route('corrective-actions.index') }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    @if ($nextNumber)
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            It will be numbered <span class="font-mono text-[var(--text-body)]">{{ $nextNumber }}</span>.
        </p>
    @endif

    <form method="POST" action="{{ route('corrective-actions.store') }}" class="space-y-6">
        @csrf

        @include('corrective-actions.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Raise the action</button>
            <a href="{{ route('corrective-actions.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
