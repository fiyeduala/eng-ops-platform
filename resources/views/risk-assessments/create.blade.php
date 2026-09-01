{{--
    A new assessment.

    IT SAVES AS A DRAFT AND THE HAZARD TABLE COMES NEXT. Nothing is approved by writing it; the
    status machine puts it on `draft` and somebody with `hse_risk.approve` — not the author — decides
    whether it stands.

    Expects: $assessment, $projects, $units, $people, $confidentialities, $types, $nextNumber
--}}
<x-layouts.app title="Write a risk assessment">

    <x-page-header title="Write a risk assessment"
                   description="The cover sheet first. The hazards are the next screen."/>

    @if ($nextNumber)
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            It will be numbered <span class="font-mono text-[var(--text-body)]">{{ $nextNumber }}</span>.
        </p>
    @endif

    <form method="POST" action="{{ route('risk-assessments.store') }}" class="space-y-6">
        @csrf

        @include('risk-assessments.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save and add hazards</button>
            <a href="{{ route('risk-assessments.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
