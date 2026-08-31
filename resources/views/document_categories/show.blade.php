{{--
    One register.

    Reads as a specification rather than a settings dump: this is the page somebody opens when they
    want to answer "why did my drawing get number X and go to Y for approval". Everything on it is a
    cause of something an author has already seen happen.

    The delete card is deliberately at the bottom and deliberately conditional. A register holding
    documents cannot be deleted at all — not by anybody, not with a confirmation — because deleting
    it would orphan or cascade its documents, and neither belongs behind a button (§59).

    Expects: $category, $extensions, $timeline, $canEdit, $canDelete
--}}
@php
    use App\Engines\Document\RevisionSequencer;

    $documentCount = $category->documents()->count();
@endphp

<x-layouts.app :title="$category->name">

    <x-page-header
        :title="$category->name"
        :description="$category->description">
        <x-slot:actions>
            <a href="{{ route('documents.index', ['category' => $category->id]) }}" class="btn btn-secondary btn-sm">
                <x-icon name="file-text" class="h-3.5 w-3.5"/> Documents here
            </a>
            @if ($canEdit)
                <a href="{{ route('document-categories.edit', $category) }}" class="btn btn-primary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (! $category->is_active)
        <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm font-medium text-[var(--text-strong)]">This register is closed.</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                No new documents can be raised into it. The {{ $documentCount }} already filed here are
                unaffected and remain readable.
            </p>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ the specification --}}
        <div class="space-y-5 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">How documents here are numbered and revised</h3>
                </div>

                <dl class="grid gap-4 p-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Number pattern</dt>
                        <dd class="mt-1 font-mono text-sm text-[var(--text-strong)]">
                            {{ $category->numbering_pattern ?: 'The organisation default' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Revision labels</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ RevisionSequencer::SCHEMES[$category->revision_scheme] ?? $category->revision_scheme }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Draft series</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($category->hasDraftSeries())
                                Drafts number {{ $category->draft_revision_prefix }}01, {{ $category->draft_revision_prefix }}02
                                before the first issued revision.
                            @else
                                None — revisions start at the issued series.
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Code</dt>
                        <dd class="mt-1 font-mono text-sm text-[var(--text-strong)]">{{ $category->code ?: '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Approval and control</h3>
                </div>

                <dl class="grid gap-4 p-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Approval</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if (! $category->requires_approval)
                                Not required. Documents are issued by their author.
                            @elseif ($category->workflow)
                                <a href="{{ route('workflows.show', $category->workflow) }}" class="hover:text-brand-600">
                                    {{ $category->workflow->name }}
                                </a>
                                <span class="text-[var(--text-muted)]">v{{ $category->workflow->version }}</span>
                            @else
                                <span class="text-amber-600">
                                    Required, but no route is set — the module default applies.
                                </span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Controlled</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $category->is_controlled ? 'Yes — revision and issue status appear on every copy.' : 'No.' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">New documents start as</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $category->defaultConfidentiality()->label() }},
                            {{ mb_strtolower($category->defaultKind()->label()) }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Retention</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($category->retention_months)
                                {{ $category->retention_months }} months
                                <span class="block text-xs text-[var(--text-muted)]">
                                    Recorded, never enforced by deletion.
                                </span>
                            @else
                                Not set
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What may be uploaded</h3>
                </div>

                <div class="space-y-3 p-5">
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($extensions as $extension)
                            <span class="rounded bg-[var(--surface-muted)] px-2 py-0.5 font-mono text-xs text-[var(--text-body)]">
                                {{ $extension }}
                            </span>
                        @endforeach
                    </div>

                    <p class="text-xs text-[var(--text-muted)]">
                        @if ($category->uploadExtensions() === null)
                            This register does not narrow the list, so the organisation-wide set applies.
                        @else
                            This register narrows the organisation-wide set to the types above.
                        @endif
                        Largest file:
                        {{ $category->max_upload_mb ? $category->max_upload_mb.' MB' : 'the organisation default' }}.
                        Executables and scripts are refused whatever this says.
                    </p>
                </div>
            </div>

            @if ($category->children->isNotEmpty())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Registers inside this one</h3>
                    </div>

                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($category->children as $child)
                            <li class="flex items-center justify-between px-5 py-3">
                                <a href="{{ route('document-categories.show', $child) }}"
                                   class="text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                    {{ $child->name }}
                                </a>
                                @unless ($child->is_active)
                                    <x-badge tone="neutral">Closed</x-badge>
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <x-timeline :entries="$timeline"
                        title="History of this register"
                        empty="Nothing has been changed here since it was created."/>
        </div>

        {{-- ------------------------------------------------------------ particulars --}}
        <div class="space-y-5">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Documents</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">{{ $documentCount }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Sits inside</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            @if ($category->parent)
                                <a href="{{ route('document-categories.show', $category->parent) }}" class="hover:text-brand-600">
                                    {{ $category->parent->name }}
                                </a>
                            @else
                                Top level
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Created</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            {{ $category->created_at?->format('j M Y') ?? '—' }}
                            @if ($category->creator)
                                <span class="block text-xs text-[var(--text-muted)]">by {{ $category->creator->name }}</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Reference</dt>
                        <dd class="mt-0.5 font-mono text-xs text-[var(--text-muted)]">{{ $category->slug }}</dd>
                    </div>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card border-red-200">
                    <div class="card-header">
                        <h3 class="card-title">Delete this register</h3>
                    </div>

                    <div class="p-5">
                        @if ($documentCount > 0 || $category->children->isNotEmpty())
                            <p class="text-sm text-[var(--text-muted)]">
                                It cannot be deleted while it holds
                                {{ $documentCount > 0 ? $documentCount.' document'.($documentCount === 1 ? '' : 's') : '' }}{{ $documentCount > 0 && $category->children->isNotEmpty() ? ' and ' : '' }}{{ $category->children->isNotEmpty() ? $category->children->count().' register'.($category->children->count() === 1 ? '' : 's') : '' }}.
                                Close it instead — that stops new documents arriving and leaves the
                                existing ones exactly where they are.
                            </p>
                        @else
                            <p class="mb-3 text-sm text-[var(--text-muted)]">
                                It is empty, so deleting it loses nothing but the configuration itself.
                            </p>
                            <x-delete-form :action="route('document-categories.destroy', $category)"
                                           label="Delete this register"
                                           :confirm="'Delete '.$category->name.'? It is empty, so nothing else goes with it.'"/>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
