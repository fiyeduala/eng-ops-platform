{{--
    A new drawing register line.

    Opened with no file attached, which is the normal state for months. The register is agreed from
    the drawing list at the start of a job; the sheets are produced against it afterwards.
--}}
<x-layouts.app title="New drawing">

    <x-page-header
        title="New drawing"
        description="Record the title block. The controlled file is attached later, from the drawing's own page.">
        <x-slot:actions>
            <a href="{{ route('drawings.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
        <p class="text-sm text-[var(--text-muted)]">
            The number field is filled with a suggestion from your company's numbering scheme. Type
            over it with the real drawing number — on most jobs that number is set by the client's
            convention, and the register is only useful if a drawing can be looked up by the number
            written on the sheet.
        </p>
    </div>

    <form method="POST" action="{{ route('drawings.store') }}" class="max-w-3xl">
        @csrf

        @include('drawings.partials.form')

        <div class="mt-6 flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Add to the register</button>
            <a href="{{ route('drawings.index') }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
