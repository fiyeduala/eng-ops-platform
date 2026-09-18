<x-layouts.app title="Add a training requirement">
    <x-page-header title="Add a training requirement" description="Say which course a position, a department or the whole company has to hold. The matrix reads it against the certificates on file."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('training-requirements.store') }}" class="card max-w-3xl p-5">
        @csrf
        @include('training-requirements.partials.form')
        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Add requirement</button>
            <a href="{{ route('training-requirements.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
