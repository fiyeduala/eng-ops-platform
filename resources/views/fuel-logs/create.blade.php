{{-- Expects: $log, $vehicles, $projects, $drivers --}}
<x-layouts.app title="Record a fill">

    <x-page-header
        title="Record a fill"
        description="A fuel receipt: what went in, what it cost, and what the clock read at the pump."/>

    <form method="POST" action="{{ route('fuel-logs.store') }}" class="space-y-6">
        @csrf

        @include('fuel-logs.partials.form')

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Record the fill</button>
            <a href="{{ route('fuel-logs.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
