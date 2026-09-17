<x-layouts.app title="New tax code">
    <x-page-header title="New tax code" description="A rate your company applies to sales, purchases or both."/>
    <form method="POST" action="{{ route('tax-codes.store') }}" class="card p-5">
        @csrf
        @include('tax-codes.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Create tax code</button><a href="{{ route('tax-codes.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
