<x-layouts.app title="Edit {{ $cycle->name }}">
    <x-page-header :title="'Edit '.$cycle->name"
                   description="Changing a line here changes the template. Reviews already raised keep the wording they were raised with."/>

    @include('finance.errors')

    <form method="POST" action="{{ route('review-cycles.update', $cycle) }}" class="card p-6">
        @csrf @method('PUT')
        @include('review-cycles.partials.form')

        <div class="mt-6 flex items-center gap-2">
            <button class="btn btn-primary">Save changes</button>
            <a href="{{ route('review-cycles.show', $cycle) }}" class="btn btn-ghost">Cancel</a>
            @can ('delete', $cycle)
                <div class="ml-auto">
                    <x-delete-form :action="route('review-cycles.destroy', $cycle)" label="Delete cycle"
                                   confirm="Delete this cycle? Reviews already raised from it are not deleted."/>
                </div>
            @endcan
        </div>
    </form>
</x-layouts.app>
