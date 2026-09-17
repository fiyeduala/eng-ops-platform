<x-layouts.app title="New leave type">
    <x-page-header title="New leave type" description="A kind of leave your company grants, with its own rules."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('leave-types.store') }}" class="card p-5">
        @csrf
        @include('leave.types.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Create leave type</button><a href="{{ route('leave-types.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
