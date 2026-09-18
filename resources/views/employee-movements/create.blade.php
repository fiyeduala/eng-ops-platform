<x-layouts.app title="Propose a movement">
    <x-page-header title="Propose a movement" description="A promotion, transfer, secondment or acting appointment. Drafting it changes nothing — it has to be submitted and approved."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('employee-movements.store') }}" class="card p-6">
        @csrf
        @include('employee-movements.partials.form')

        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save draft</button>
            <a href="{{ route('employee-movements.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
