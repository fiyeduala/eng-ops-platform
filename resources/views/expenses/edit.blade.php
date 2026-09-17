<x-layouts.app :title="'Edit '.$expense->number">
    <x-page-header :title="'Edit '.$expense->number" description="Only a draft claim can be changed."/>
    <form method="POST" action="{{ route('expenses.update', $expense) }}" class="card p-5">
        @csrf @method('PUT')
        @include('expenses.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('expenses.show', $expense) }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
