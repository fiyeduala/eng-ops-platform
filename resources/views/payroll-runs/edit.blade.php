<x-layouts.app :title="'Edit '.$run->number">
    <x-page-header :title="'Edit '.$run->number"
                   description="Changing the period does not change the figures. Calculate the run again afterwards, or it will still be paying for the dates it was calculated against."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('payroll-runs.update', $run) }}" class="card p-6">
        @csrf @method('PUT')
        @include('payroll-runs.partials.form')

        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save changes</button>
            <a href="{{ route('payroll-runs.show', $run) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
