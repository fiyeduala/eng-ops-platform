<x-layouts.app title="Record income">
    <x-page-header title="Record income" description="Saved as a draft. Issue it when the client has been billed."/>
    <form method="POST" action="{{ route('incomes.store') }}" class="card p-5">
        @csrf
        @include('incomes.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save draft</button><a href="{{ route('incomes.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
