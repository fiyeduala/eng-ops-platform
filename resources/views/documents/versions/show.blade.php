{{--
    One revision, read only.

    Separate from the editor on purpose. Most people who open a controlled document are reading it,
    not amending it, and putting them in an editor invites the accident this whole module exists to
    prevent. It is also the page that has to say plainly WHICH revision this is — reading Rev B while
    Rev D is in force is the single most expensive mistake in document control, so a superseded
    revision says so before the text starts.

    Expects: $document, $version, $canDownload, $canPrint, $canEdit
--}}
<x-layouts.app :title="'Rev '.$version->revision.' · '.$document->displayName()">

    <x-page-header
        :title="$document->title"
        :description="($document->number ?? 'Unnumbered').' · Revision '.$version->revision">
        <x-slot:actions>
            <a href="{{ route('documents.show', $document) }}" class="btn btn-sm btn-ghost">All revisions</a>

            @if ($canEdit && ! $version->isApproved())
                <a href="{{ route('documents.versions.edit', [$document, $version]) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canDownload && $version->hasFile())
                <a href="{{ route('documents.versions.download', [$document, $version]) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="download" class="h-3.5 w-3.5"/> Download file
                </a>
            @endif

            @if ($canDownload && $version->hasContent())
                <a href="{{ route('documents.versions.docx', [$document, $version]) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="download" class="h-3.5 w-3.5"/> Word
                </a>
            @endif

            @if ($canPrint)
                <a href="{{ route('documents.versions.pdf', [$document, $version]) }}"
                   class="btn btn-sm btn-secondary" target="_blank" rel="noopener">
                    <x-icon name="file-text" class="h-3.5 w-3.5"/> PDF
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- which revision --}}
    @if ($version->isSuperseded() || ! $version->is_current)
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <div class="text-sm text-[var(--text-body)]">
                <p class="font-medium">This is not the current revision.</p>
                <p class="mt-1 text-[var(--text-muted)]">
                    @if ($document->currentVersion)
                        {{ $document->number ?? 'This document' }} currently means revision
                        <a href="{{ route('documents.versions.show', [$document, $document->currentVersion]) }}"
                           class="font-mono font-medium text-brand-600 hover:underline">{{ $document->currentVersion->revision }}</a>.
                        This one is kept because it is what people worked to at the time.
                    @else
                        Nothing has been made current yet.
                    @endif
                </p>
            </div>
        </div>
    @elseif ($version->isApproved())
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-emerald-400 p-4">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p class="text-sm text-[var(--text-body)]">
                Approved {{ $version->approved_at?->format('j M Y') }}
                @if ($version->approver)
                    by {{ $version->approver->name }}
                @endif
                — this is what {{ $document->number ?? 'this document' }} means today.
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-4">

        {{-- ---------------------------------------------------------- particulars --}}
        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Revision {{ $version->revision }}</h3>
                    @if ($version->status)
                        <x-badge :tone="$version->status->tone()">{{ $version->status->label }}</x-badge>
                    @endif
                </div>

                <dl class="divide-y divide-[var(--surface-border)] text-sm">
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Author</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $version->author?->name ?? 'Unknown' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Raised</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $version->created_at?->format('j M Y, H:i') }}</dd>
                    </div>
                    @if ($version->approved_at)
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Approved</dt>
                            <dd class="text-right text-[var(--text-body)]">
                                {{ $version->approved_at->format('j M Y') }}
                                <span class="block text-xs text-[var(--text-muted)]">{{ $version->approver?->name }}</span>
                            </dd>
                        </div>
                    @endif
                    @if ($version->superseded_at)
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Superseded</dt>
                            <dd class="text-right text-[var(--text-body)]">{{ $version->superseded_at->format('j M Y') }}</dd>
                        </div>
                    @endif
                    @if ($version->hasFile())
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">File</dt>
                            <dd class="min-w-0 text-right text-[var(--text-body)]">
                                <span class="block truncate">{{ $version->original_name }}</span>
                                <span class="block text-xs text-[var(--text-muted)]">{{ $version->fileSizeForHumans() }}</span>
                            </dd>
                        </div>
                        <div class="px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Checksum</dt>
                            <dd class="mt-1 break-all font-mono text-xs text-[var(--text-muted)]">{{ $version->file_hash }}</dd>
                        </div>
                    @elseif ($version->word_count)
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Length</dt>
                            <dd class="text-right text-[var(--text-body)]">{{ number_format((int) $version->word_count) }} words</dd>
                        </div>
                    @endif
                </dl>

                @if ($version->change_summary)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <p class="label">What changed</p>
                        <p class="text-sm text-[var(--text-body)]">{{ $version->change_summary }}</p>
                    </div>
                @endif
            </div>

            {{-- ------------------------------------------------------ signatures --}}
            @if ($version->relationLoaded('signatures') && $version->signatures->isNotEmpty())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Signatures</h3>
                    </div>

                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($version->signatures as $signature)
                            <li class="px-5 py-3">
                                <p class="text-sm font-medium text-[var(--text-strong)]">
                                    {{ $signature->name_snapshot ?? $signature->user?->name ?? 'Unknown' }}
                                </p>
                                {{-- The designation is the one held ON THE DAY, not the one held
                                     today. A promotion next year must not silently rewrite who
                                     approved this drawing as what they have since become. --}}
                                @if ($signature->designation_snapshot)
                                    <p class="text-xs text-[var(--text-body)]">{{ $signature->designation_snapshot }}</p>
                                @endif
                                <p class="text-xs text-[var(--text-muted)]">
                                    {{ ucfirst((string) $signature->action) }}
                                    @if ($signature->signed_at)
                                        · {{ $signature->signed_at->format('j M Y, H:i') }}
                                    @endif
                                </p>
                                @if ($signature->content_hash)
                                    <p class="mt-1 break-all font-mono text-[10px] text-[var(--text-muted)]">{{ $signature->content_hash }}</p>

                                    {{-- An approved revision cannot be edited, so these can only
                                         disagree if the stored bytes were changed underneath the
                                         signature. That is an incident, not a footnote, and it is
                                         said on the page rather than left for somebody to notice
                                         by comparing two hex strings by eye. --}}
                                    @unless ($signature->matches($version->contentHash()))
                                        <p class="mt-1 text-xs font-medium text-red-600">
                                            This signature does not match the current content of the revision.
                                        </p>
                                    @endunless
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                        Each signature is bound to the exact bytes of this revision, so it can never be
                        carried onto altered text. These are internal electronic approval signatures —
                        evidence of who approved what and when, not qualified signatures under eIDAS or
                        ESIGN.
                    </p>
                </div>
            @endif
        </div>

        {{-- ---------------------------------------------------------- content --}}
        <div class="lg:col-span-3">
            @if ($version->hasContent())
                <article class="document-canvas">
                    <div class="prose-document">
                        {!! $version->content_html !!}
                    </div>
                </article>
            @elseif ($version->hasFile())
                <div class="card">
                    <x-empty-state
                        icon="file-text"
                        title="This revision is a file"
                        description="Uploaded documents are versioned and guarded without being read — the system does not pretend to understand what is inside a vendor's PDF or a CAD drawing.">
                        @if ($canDownload)
                            <x-slot:actions>
                                <a href="{{ route('documents.versions.download', [$document, $version]) }}"
                                   class="btn btn-primary btn-sm">
                                    <x-icon name="download" class="h-3.5 w-3.5"/>
                                    Download {{ $version->original_name }}
                                </a>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                </div>
            @else
                <div class="card">
                    <x-empty-state
                        icon="file-text"
                        title="This revision is empty"
                        description="It has been raised but nothing has been written or uploaded into it yet."/>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
