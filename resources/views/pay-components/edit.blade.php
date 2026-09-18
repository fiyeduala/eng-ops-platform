<x-layouts.app :title="'Edit '.$payComponent->code">
    <x-page-header :title="'Edit '.$payComponent->code"
                   description="Changing a rule changes future runs only. Payslips already approved keep the rate they were calculated under, and their own copy of it, so an old payslip still explains itself."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('pay-components.update', $payComponent) }}" class="card p-6">
        @csrf @method('PUT')
        @include('pay-components.partials.form')

        <div class="mt-6 flex items-center gap-2">
            <button class="btn btn-primary">Save changes</button>
            <a href="{{ route('pay-components.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

    @can('delete', $payComponent)
        <div class="card mt-6 p-5">
            <h2 class="card-title">Remove this component</h2>
            <p class="mt-1 text-xs text-[var(--text-muted)]">
                Only possible while nothing has ever been calculated from it. Once it has appeared on a payslip,
                retire it by unticking “Active” instead — deleting it would take a line off somebody's pay history.
            </p>
            <div class="mt-3">
                <x-delete-form :action="route('pay-components.destroy', $payComponent)" label="Remove component" confirm="Remove this component from the pay structure?"/>
            </div>
        </div>
    @endcan
</x-layouts.app>
