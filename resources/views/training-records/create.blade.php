{{--
    Recording a certificate.

    Expects: $record, $courses, $results, $people, $projects, $units, $confidentialities,
             $certificates, $nextNumber
--}}
<x-layouts.app title="Record a certificate">

    <x-page-header title="Record a certificate"
                   description="Who holds it, what it is, and when it runs out."/>

    @if ($nextNumber)
        <p class="mb-6 text-sm text-[var(--text-muted)]">
            It will be numbered <span class="font-mono text-[var(--text-body)]">{{ $nextNumber }}</span>.
        </p>
    @endif

    <form method="POST" action="{{ route('training-records.store') }}" class="space-y-6">
        @csrf

        @include('training-records.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Record it</button>
            <a href="{{ route('training-records.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
