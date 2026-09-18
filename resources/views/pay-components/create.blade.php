<x-layouts.app title="Add a pay component">
    <x-page-header title="Add a pay component"
                   description="One rule — an allowance, a tax, a contribution, a deduction. Describe what it does and the calculator will apply it, whatever it is and wherever you are."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('pay-components.store') }}" class="card p-6">
        @csrf
        @include('pay-components.partials.form')

        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Add component</button>
            <a href="{{ route('pay-components.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
