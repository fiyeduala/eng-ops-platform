{{--
    One template.

    Shows the body as it will actually appear, with the placeholder names still standing, because
    that is the honest preview: nobody has picked a document yet, so there is nothing to fill them in
    from. The PDF preview does the same thing with a PREVIEW watermark.

    Rendering the body against a real record to "see how it looks" is deliberately not offered. It
    would put that record's content behind a template permission, and template permissions are held
    by administrators who may have no right at all to the document they happened to pick (§61).

    Expects: $template, $placeholders, $timeline, $canEdit, $canDuplicate, $canDelete
--}}
@php
    $used = collect($template->placeholders ?? [])->map(fn ($p) => '{{'.$p.'}}')->all();
@endphp

<x-layouts.app :title="$template->name">

    <x-page-header
        :title="$template->name"
        :description="$template->description">
        <x-slot:actions>
            <a href="{{ route('templates.preview', $template) }}" target="_blank" rel="noopener"
               class="btn btn-secondary btn-sm">
                <x-icon name="file-text" class="h-3.5 w-3.5"/> Preview PDF
            </a>

            @if ($canDuplicate)
                <form method="POST" action="{{ route('templates.duplicate', $template) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <x-icon name="plus" class="h-3.5 w-3.5"/> Duplicate
                    </button>
                </form>
            @endif

            @if ($canEdit)
                <a href="{{ route('templates.edit', $template) }}" class="btn btn-primary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @unless ($template->is_active)
        <div class="mb-5 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm font-medium text-[var(--text-strong)]">This template has been retired.</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                It is no longer offered when creating a document. Everything already written from it
                keeps the content it was given.
            </p>
        </div>
    @endunless

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ the body --}}
        <div class="space-y-5 lg:col-span-2">

            @if ($template->header_html)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Header</h3>
                    </div>
                    <div class="prose-document p-5 text-sm">{!! $template->header_html !!}</div>
                </div>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Body</h3>
                </div>

                <div class="p-5">
                    @if (filled($template->content_html))
                        <div class="document-canvas">
                            <div class="prose-document">{!! $template->content_html !!}</div>
                        </div>
                    @else
                        <p class="text-sm text-[var(--text-muted)]">
                            This template has no body yet. A document created from it would start
                            empty, which is a perfectly reasonable thing for a template that exists
                            only to carry a letterhead and page setup.
                        </p>
                    @endif
                </div>
            </div>

            @if ($template->footer_html)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Footer</h3>
                    </div>
                    <div class="prose-document p-5 text-sm">{!! $template->footer_html !!}</div>
                </div>
            @endif

            {{-- ------------------------------------------------------ placeholders --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Placeholders</h3>
                </div>

                <div class="space-y-4 p-5">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Used in this template</p>
                        @if ($used === [])
                            <p class="mt-1 text-sm text-[var(--text-muted)]">
                                None. Every document made from this template starts with identical
                                wording.
                            </p>
                        @else
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach ($used as $token)
                                    <span class="rounded bg-[var(--surface-muted)] px-2 py-0.5 font-mono text-xs text-[var(--text-body)]">
                                        {{ $token }}
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div>
                        <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Available</p>
                        <dl class="mt-2 space-y-3">
                            @foreach ($placeholders as $namespace => $tokens)
                                <div>
                                    <dt class="text-xs font-medium text-[var(--text-body)]">{{ $namespace }}</dt>
                                    <dd class="mt-1 flex flex-wrap gap-1.5">
                                        @foreach ($tokens as $token)
                                            <span class="rounded border border-[var(--surface-border)] px-2 py-0.5 font-mono text-xs text-[var(--text-muted)]">
                                                {{ $token }}
                                            </span>
                                        @endforeach
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    <p class="text-xs text-[var(--text-muted)]">
                        Anything not on this list is left exactly as typed. Placeholders are a
                        substitution from a fixed list of readable fields, not an expression
                        language &mdash; nothing written into a template is ever executed.
                    </p>
                </div>
            </div>

            <x-timeline :entries="$timeline"
                        title="History of this template"
                        empty="Nothing has changed since it was created."/>
        </div>

        {{-- ------------------------------------------------------------ particulars --}}
        <div class="space-y-5">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Kind</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">{{ $template->kindLabel() }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Register</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            @if ($template->category)
                                <a href="{{ route('document-categories.show', $template->category) }}"
                                   class="hover:text-brand-600">{{ $template->category->name }}</a>
                            @else
                                Offered in every register
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Page</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            {{ $template->page_size }}, {{ $template->isLandscape() ? 'landscape' : 'portrait' }}
                            @php $m = $template->marginSet(); @endphp
                            <span class="block text-xs text-[var(--text-muted)]">
                                Margins {{ $m['top'] }}/{{ $m['right'] }}/{{ $m['bottom'] }}/{{ $m['left'] }} mm
                            </span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Status</dt>
                        <dd class="mt-0.5">
                            <x-badge :tone="$template->is_active ? 'success' : 'neutral'">
                                {{ $template->is_active ? 'In use' : 'Retired' }}
                            </x-badge>
                            @if ($template->is_default)
                                <x-badge tone="brand">Default</x-badge>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Created</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            {{ $template->created_at?->format('j M Y') ?? '—' }}
                            @if ($template->creator)
                                <span class="block text-xs text-[var(--text-muted)]">by {{ $template->creator->name }}</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Reference</dt>
                        <dd class="mt-0.5 font-mono text-xs text-[var(--text-muted)]">{{ $template->slug }}</dd>
                    </div>
                </dl>
            </div>

            @if ($canEdit)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Availability</h3>
                    </div>

                    <div class="p-5">
                        <form method="POST" action="{{ route('templates.activate', $template) }}">
                            @csrf
                            <input type="hidden" name="active" value="{{ $template->is_active ? 0 : 1 }}">

                            <p class="mb-3 text-sm text-[var(--text-muted)]">
                                @if ($template->is_active)
                                    Retiring it takes it off the list authors choose from. Nothing
                                    already written is touched.
                                @else
                                    Bringing it back makes it selectable again when a document is
                                    created.
                                @endif
                            </p>

                            <button type="submit" class="btn btn-secondary btn-sm">
                                {{ $template->is_active ? 'Retire this template' : 'Put it back in use' }}
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            @if ($canDelete)
                <div class="card border-red-200">
                    <div class="card-header">
                        <h3 class="card-title">Delete this template</h3>
                    </div>

                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            Documents written from it keep their content, so nothing is lost but the
                            template itself. Retiring it is usually the better move &mdash; it keeps
                            the history of what the house style used to be.
                        </p>

                        <x-delete-form :action="route('templates.destroy', $template)"
                                       label="Delete this template"
                                       :confirm="'Delete '.$template->name.'? Documents made from it keep their content.'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
