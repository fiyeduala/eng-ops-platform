{{--
    The document register.

    This is the screen that has to answer "what does drawing 1042 say today, and who signed it off",
    so the number and the revision are given equal billing with the title — engineers quote numbers,
    not titles.

    THREE INDEPENDENT FILTERS ARE AT WORK HERE and the copy keeps them apart, because conflating them
    is how people mis-read their own access:

      - REACH decides which rows exist at all. One ScopeCompiler call on `document.view`.
      - CONFIDENTIALITY is a second axis applied in the same SQL, not by dropping rows afterwards —
        so the paging totals below are the truth rather than an over-count of things you cannot open.
      - THE VIEW SELECTOR is an ordinary filter on top of both, and changing it can never widen
        either.

    Expects: $documents, $categories, $projects, $statuses, $views, $filters, $canCreate
--}}
<x-layouts.app title="Documents">

    <x-page-header
        title="Documents"
        description="The controlled register. A number is an identity that outlives every revision filed under it — which is why nothing here is ever overwritten, only superseded.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('documents.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New document
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('documents.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Title or document number"/>
                <p class="help">Titles and numbers only — document bodies are not searched from here.</p>
            </div>

            <div>
                <label for="view" class="label">Show</label>
                <x-select name="view" :options="$views" :selected="$filters['view']"/>
            </div>

            <div>
                <label for="status" class="label">Status</label>
                <x-select name="status" :options="$statuses" :selected="$filters['status']" placeholder="Any status"/>
            </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="category" class="label">Register</label>
                <x-select name="category" :options="$categories" :selected="$filters['category']" placeholder="Any register"/>
            </div>

            <div>
                <label for="project" class="label">Project</label>
                <x-select name="project" :options="$projects" :selected="$filters['project']" placeholder="Any project"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if (array_filter($filters, fn ($v, $k) => $v && ! ($k === 'view' && $v === 'active'), ARRAY_FILTER_USE_BOTH))
                <a href="{{ route('documents.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($documents->isEmpty())
            <x-empty-state
                icon="file-text"
                title="No documents you can see"
                description="Either nothing matches this filter, or the documents it would match sit outside your reach or above your clearance — from here those look identical, and deliberately so."/>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Rev</th>
                            <th>Status</th>
                            <th>Register</th>
                            <th>Where</th>
                            <th>Owner</th>
                            <th>Updated</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td>
                                    <a href="{{ route('documents.show', $document) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $document->title }}</a>
                                    <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">
                                        {{ $document->number ?? 'Unnumbered' }}
                                    </span>

                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        @if ($document->trashed())
                                            <x-badge tone="neutral">
                                                <x-icon name="archive" class="h-3 w-3"/> Archived
                                            </x-badge>
                                        @endif

                                        @if ($document->isLocked())
                                            <x-badge tone="warning">
                                                <x-icon name="lock" class="h-3 w-3"/>
                                                Checked out{{ $document->lockedBy ? ' · '.$document->lockedBy->name : '' }}
                                            </x-badge>
                                        @endif

                                        @if ($document->kind?->value === 'file')
                                            <x-badge tone="neutral">Uploaded file</x-badge>
                                        @endif
                                    </div>
                                </td>

                                <td class="whitespace-nowrap">
                                    @if ($document->currentVersion)
                                        <span class="font-mono text-sm text-[var(--text-strong)]">{{ $document->currentVersion->revision }}</span>
                                        @if ($document->currentVersion->approved_at)
                                            <span class="mt-0.5 block text-xs text-emerald-600">Approved</span>
                                        @endif
                                    @else
                                        <span class="text-xs italic text-[var(--text-muted)]">No revision</span>
                                    @endif
                                    <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                        {{ (int) $document->versions_count }} in all
                                    </span>
                                </td>

                                <td>
                                    @if ($document->status)
                                        <x-badge :tone="$document->status->tone()">{{ $document->status->label }}</x-badge>
                                    @else
                                        <span class="text-[var(--text-muted)]">—</span>
                                    @endif
                                </td>

                                <td class="text-[var(--text-muted)]">
                                    {{ $document->category?->name ?? 'Unfiled' }}
                                </td>

                                <td class="text-[var(--text-muted)]">
                                    {{ $document->project?->name ?? 'No project' }}
                                    @if ($document->orgUnit)
                                        <span class="mt-0.5 block text-xs">{{ $document->orgUnit->name }}</span>
                                    @endif
                                </td>

                                <td class="text-[var(--text-muted)]">{{ $document->owner?->name ?? '—' }}</td>

                                <td class="whitespace-nowrap text-[var(--text-muted)]">
                                    {{ $document->updated_at?->format('j M Y') }}
                                </td>

                                <td class="text-right">
                                    <a href="{{ route('documents.show', $document) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-[var(--text-muted)]">
                    Showing {{ $documents->firstItem() }}–{{ $documents->lastItem() }} of {{ $documents->total() }} you can see.
                </p>
                @if ($documents->hasPages())
                    {{ $documents->links() }}
                @endif
            </div>
        @endif
    </div>

</x-layouts.app>
