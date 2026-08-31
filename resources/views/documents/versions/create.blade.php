{{--
    Raise the next revision.

    The revision label is NOT an input. RevisionSequencer derives it from what already exists — which
    is why the preview below can be shown without consuming anything, and why abandoning this form
    leaves no gap in the series. A form that could name its own revision would let somebody write
    "Rev C" twice, which is exactly the failure a controlled register exists to prevent.

    WHICH KIND OF REVISION THIS IS gets decided by whether a file arrives, not by a radio button. A
    posted flag would be a second source of truth about the same fact, and the two would eventually
    disagree.

    Expects: $document, $previous, $nextRevision, $allowedExtensions, $maxUploadMb, $canAuthor
--}}
<x-layouts.app :title="'New revision · '.$document->displayName()">

    <x-page-header
        :title="'New revision of '.$document->title"
        :description="($document->number ?? 'Unnumbered').' — this will be '.$nextRevision.'. The revision before it is not touched.'">
        <x-slot:actions>
            <a href="{{ route('documents.show', $document) }}" class="btn btn-sm btn-ghost">Back to document</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('documents.versions.store', $document) }}"
          enctype="multipart/form-data" class="max-w-3xl">
        @csrf

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Revision {{ $nextRevision }}</h3>
                @if ($previous)
                    <span class="text-xs text-[var(--text-muted)]">
                        Supersedes {{ $previous->revision }}
                    </span>
                @endif
            </div>

            <div class="space-y-5 p-5">
                @if ($previous)
                    <div class="rounded-md bg-[var(--surface-muted)] px-3 py-2 text-sm text-[var(--text-muted)]">
                        Revision <span class="font-mono font-medium text-[var(--text-strong)]">{{ $previous->revision }}</span>
                        stays exactly as it is. It becomes superseded once this one is made current — it is
                        never edited and never deleted.
                    </div>
                @endif

                <x-field label="What changed" name="change_summary"
                         help="The one line a reviewer reads before opening it. Vague summaries are what make a revision history useless three years later.">
                    <x-textarea name="change_summary" rows="3"
                                placeholder="e.g. Clause 7.4 revised following client comment CL-118; Appendix B added."/>
                </x-field>

                @if ($canAuthor)
                    <div class="rounded-md border border-dashed border-[var(--surface-border)] p-4">
                        <p class="text-sm font-medium text-[var(--text-strong)]">Written here</p>
                        <p class="mt-1 text-sm text-[var(--text-muted)]">
                            Saving opens the editor with a copy of
                            {{ $previous ? 'revision '.$previous->revision : 'a blank page' }}, so you amend
                            rather than retype. Leave the file box below empty.
                        </p>
                    </div>
                @endif

                <x-field :label="$canAuthor ? 'Or upload a file instead' : 'File'" name="file"
                         :required="! $canAuthor"
                         :help="'Up to '.$maxUploadMb.' MB. Accepted: '.implode(', ', $allowedExtensions).'. Executable types are refused whatever they are renamed to.'">
                    <input type="file" name="file" id="file"
                           accept="{{ collect($allowedExtensions)->map(fn ($e) => '.'.$e)->implode(',') }}"
                           class="input file:mr-3 file:rounded-md file:border-0 file:bg-[var(--surface-muted)] file:px-3 file:py-1.5 file:text-sm file:font-medium">
                    @error('file')
                        <p class="error" role="alert">{{ $message }}</p>
                    @enderror
                </x-field>

                @if ($canAuthor)
                    <p class="text-xs text-[var(--text-muted)]">
                        A .docx dropped here is imported into the editor rather than stored as an opaque
                        file — the text, headings, lists and tables come across. Tracked changes, exact
                        pagination and embedded objects do not, and never will through this route.
                    </p>
                @endif
            </div>

            <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
                <a href="{{ route('documents.show', $document) }}" class="btn btn-ghost">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise {{ $nextRevision }}
                </button>
            </div>
        </div>
    </form>

</x-layouts.app>
