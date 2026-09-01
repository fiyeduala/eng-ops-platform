{{--
    Reporting an observation.

    THERE IS NO DRAFT AND NO STATUS FIELD. Pressing the button files the card; the status machine
    puts it on `open`. A near-miss scheme with a draft state accumulates cards nobody ever submits.

    Expects: $observation, $projects, $units, $confidentialities, $types, $categories, $riskLevels,
             $nextNumber
--}}
<x-layouts.app title="Report an observation">

    <x-page-header title="Report an observation"
                   description="A near miss, an unsafe condition, an unsafe act — or something done well."/>

    @if ($nextNumber)
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            It will be numbered <span class="font-mono text-[var(--text-body)]">{{ $nextNumber }}</span>.
        </p>
    @endif

    <form method="POST" action="{{ route('hse-observations.store') }}" class="space-y-6">
        @csrf

        @include('hse-observations.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">File the card</button>
            <a href="{{ route('hse-observations.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
