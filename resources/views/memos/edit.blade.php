<x-layouts.app :title="'Edit '.$memo->number">

    <x-page-header
        :title="'Edit '.$memo->subject"
        :description="$memo->number.' · the distribution list is managed from the memo page, and only a draft can be edited at all.'"/>

    <form method="POST" action="{{ route('memos.update', $memo) }}" class="card max-w-4xl">
        @csrf
        @method('PUT')

        @include('memos.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('memos.show', $memo) }}" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <x-icon name="check" class="h-3.5 w-3.5"/> Save changes
            </button>
        </div>
    </form>

</x-layouts.app>
