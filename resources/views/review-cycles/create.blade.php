<x-layouts.app title="New review cycle">
    <x-page-header title="New review cycle" description="Set the period, the scale and the questions. Nothing is raised for anybody until the round is opened."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('review-cycles.store') }}" class="card p-6">
        @csrf
        @include('review-cycles.partials.form')

        <div class="mt-6 flex gap-2">
            <button class="btn btn-primary">Create cycle</button>
            <a href="{{ route('review-cycles.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>
</x-layouts.app>
