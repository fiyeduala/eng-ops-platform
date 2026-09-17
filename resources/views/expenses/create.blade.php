<x-layouts.app title="New expense claim">
    <x-page-header title="New expense claim" description="Saved as a draft. Submit it when the receipt details are complete."/>
    <form method="POST" action="{{ route('expenses.store') }}" class="card p-5">
        @csrf
        @include('expenses.partials.form')
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save draft</button><a href="{{ route('expenses.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
