<x-layouts.app title="Open an exit">
    <x-page-header title="Open an exit" description="Opening an exit starts the leaving checklist. It does not end the employment — completing it does, and only once they hold nothing."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('employee-exits.store') }}" class="card max-w-3xl p-5">
        @csrf
        @include('employee-exits.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Open exit</button>
            <a href="{{ route('employee-exits.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
