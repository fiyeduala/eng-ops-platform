{{--
    Scheduling an inspection.

    SAVING TAKES YOU TO THE CHECKLIST, not back to the register. An inspection with no items is a
    diary entry rather than an inspection, and the controller redirects to the items screen for
    exactly that reason.

    Expects: $inspection, $projects, $units, $people, $confidentialities, $types, $nextNumber
--}}
<x-layouts.app title="Schedule an inspection">

    <x-page-header title="Schedule an inspection"
                   description="What is being walked and when. The checklist is the next screen."/>

    @if ($nextNumber)
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            It will be numbered <span class="font-mono text-[var(--text-body)]">{{ $nextNumber }}</span>.
        </p>
    @endif

    <form method="POST" action="{{ route('hse-inspections.store') }}" class="space-y-6">
        @csrf

        @include('hse-inspections.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save and set the checklist</button>
            <a href="{{ route('hse-inspections.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
