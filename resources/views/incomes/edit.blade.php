<x-layouts.app :title="'Edit '.$income->number">
    <x-page-header :title="'Edit '.$income->number" description="Only draft income can be changed."/>
    <form method="POST" action="{{ route('incomes.update', $income) }}" class="card p-5">
        @csrf @method('PUT')
        @include('incomes.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save changes</button><a href="{{ route('incomes.show', $income) }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
