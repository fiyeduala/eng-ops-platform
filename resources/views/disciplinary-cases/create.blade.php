<x-layouts.app title="Open a case">
    <x-page-header title="Open a case" description="Records an allegation and starts the process. The finding comes later, from somebody entitled to make it."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('disciplinary-cases.store') }}" class="card p-6">
        @csrf
        @include('disciplinary-cases.partials.form')

        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Open case</button>
            <a href="{{ route('disciplinary-cases.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
