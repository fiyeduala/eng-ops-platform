<x-layouts.app title="Open a payroll run">
    <x-page-header title="Open a payroll run"
                   description="A period, nothing more. Opening it pays nobody and calculates nothing — that is a separate, deliberate act."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('payroll-runs.store') }}" class="card p-6">
        @csrf
        @include('payroll-runs.partials.form')

        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Open the run</button>
            <a href="{{ route('payroll-runs.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
