<x-layouts.app :title="'Edit '.$position->title">

    <x-page-header
        :title="'Edit '.$position->title"
        description="Moving a title to another unit files it out of the current unit’s list — and out of the sight of anyone whose reach stops there."/>

    <form method="POST" action="{{ route('positions.update', $position) }}" class="card max-w-3xl">
        @csrf
        @method('PUT')

        @include('positions.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('positions.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>

</x-layouts.app>
