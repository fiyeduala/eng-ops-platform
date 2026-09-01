{{--
    Planning a talk.

    Expects: $talk, $projects, $units, $people, $confidentialities, $handouts, $nextNumber
--}}
<x-layouts.app title="Plan a toolbox talk">

    <x-page-header title="Plan a toolbox talk"
                   description="The briefing itself. Attendance is recorded afterwards, on the talk."/>

    @if ($nextNumber)
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            It will be numbered <span class="font-mono text-[var(--text-body)]">{{ $nextNumber }}</span>.
        </p>
    @endif

    <form method="POST" action="{{ route('toolbox-talks.store') }}" class="space-y-6">
        @csrf

        @include('toolbox-talks.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the talk</button>
            <a href="{{ route('toolbox-talks.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
