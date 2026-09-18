<x-layouts.app :title="'Edit '.$exit->number">
    <x-page-header :title="'Edit '.$exit->number" :description="$exit->employee?->fullName()"/>
    @include('finance.errors')
    <form method="POST" action="{{ route('employee-exits.update', $exit) }}" class="card max-w-3xl p-5">
        @csrf @method('PUT')
        @include('employee-exits.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Save</button>
            <a href="{{ route('employee-exits.show', $exit) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
