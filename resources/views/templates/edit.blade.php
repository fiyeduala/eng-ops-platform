{{--
    Edit a template.

    The banner is the important part of this screen. Editing a template does not reach back into the
    documents written from it — their content became theirs the moment they were created — and an
    administrator who expects otherwise will change the letterhead and then wonder why last month's
    procedures still carry the old one.
--}}
<x-layouts.app :title="'Edit '.$template->name">

    <x-page-header
        :title="'Edit '.$template->name"
        description="Changes apply to documents created from now on.">
        <x-slot:actions>
            <a href="{{ route('templates.preview', $template) }}" target="_blank" rel="noopener"
               class="btn btn-secondary btn-sm">
                <x-icon name="file-text" class="h-3.5 w-3.5"/> Preview
            </a>
            <a href="{{ route('templates.show', $template) }}" class="btn btn-ghost btn-sm">Back to the template</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-strong)]">Existing documents are not affected.</p>
        <p class="mt-1 text-sm text-[var(--text-muted)]">
            A document takes a copy of the template when it is created, and keeps it. That is
            deliberate: a revision somebody approved must still read the way it read when they
            approved it.
        </p>
    </div>

    <form method="POST" action="{{ route('templates.update', $template) }}" class="max-w-4xl">
        @csrf
        @method('PUT')

        @include('templates.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="{{ route('templates.show', $template) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
