{{--
    One document.

    The page has to hold two ideas at once without blurring them, because blurring them is what
    every shared-drive document system gets wrong:

      - THE DOCUMENT is an identity. Its number, its owner, its classification, where it is filed.
        It has no text.
      - THE REVISIONS are what the number meant on a given day. One of them is current; the rest are
        superseded, and nothing here can delete or rewrite an approved one.

    Every control below was drawn from a policy answer, and every one of them asks again when pressed
    (§84). The three that look similar but are not:

      - CHECK OUT / CHECK IN is a lock, not a permission. Holding `document.edit` and finding the
        document checked out by somebody else means waiting, not overriding — the force option is
        `document.manage` and it says so.
      - SUBMIT starts the configured approval route. There is no "approve" button on this page,
        deliberately: approving happens in the approvals inbox, against the stage that asked.
      - DOWNLOAD, EXPORT and PRINT are three different authorities, and each one is recorded. "Who
        took a copy of a controlled procedure, when, and in what form" cannot be reconstructed after
        the fact, so it is written down as it happens (§59, §67).

    Expects: $document, $versions, $current, $instance, $timeline, $canEdit, $canCreateVersion,
             $canCheckOut, $canCheckIn, $canSubmit, $canWithdraw, $canDownload, $canPrint,
             $canShare, $canArchive, $canDelete
--}}
@php
    $lockedByMe = $document->isLockedBy(auth()->user());
    $lockedByOther = $document->isLockedByAnother(auth()->user());
@endphp

<x-layouts.app :title="$document->displayName()">

    <x-page-header
        :title="$document->title"
        :description="($document->number ?? 'Unnumbered').($document->project ? ' · '.$document->project->name : '').($document->category ? ' · '.$document->category->name : '')">
        <x-slot:actions>
            @if ($canCreateVersion)
                <a href="{{ route('documents.versions.create', $document) }}" class="btn btn-sm btn-primary">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New revision
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('documents.edit', $document) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit details
                </a>
            @endif

            @if ($document->trashed())
                @if ($canArchive)
                    <form method="POST" action="{{ route('documents.restore', $document->getKey()) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">
                            <x-icon name="archive" class="h-3.5 w-3.5"/> Restore
                        </button>
                    </form>
                @endif
            @elseif ($canArchive)
                <form method="POST" action="{{ route('documents.archive', $document) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-ghost">
                        <x-icon name="archive" class="h-3.5 w-3.5"/> Archive
                    </button>
                </form>
            @endif

            @if ($canDelete)
                <x-delete-form :action="route('documents.destroy', $document)"
                               label="Delete"
                               confirm="Delete {{ $document->number ?? $document->title }} and every revision under it?"/>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- banners --}}
    @if ($document->trashed())
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-slate-400 p-4">
            <x-icon name="archive" class="mt-0.5 h-4 w-4 shrink-0 text-slate-500"/>
            <p class="text-sm text-[var(--text-body)]">
                This document is archived. It keeps its number and every revision under it — archiving
                takes it out of circulation, it does not take it out of the record.
            </p>
        </div>
    @endif

    @if ($document->isLocked())
        <div class="card mb-6 flex items-start gap-3 border-l-4 {{ $lockedByMe ? 'border-l-brand-400' : 'border-l-amber-400' }} p-4">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 {{ $lockedByMe ? 'text-brand-600' : 'text-amber-600' }}"/>
            <div class="min-w-0 flex-1 text-sm text-[var(--text-body)]">
                @if ($lockedByMe)
                    <p class="font-medium">You have this document checked out.</p>
                    <p class="mt-1 text-[var(--text-muted)]">
                        Nobody else can edit it until you check it back in.
                        @if ($document->lock_expires_at)
                            The lock lapses on its own {{ $document->lock_expires_at->diffForHumans() }}.
                        @endif
                    </p>
                @else
                    <p class="font-medium">
                        Checked out by {{ $document->lockedBy?->name ?? 'someone else' }}
                        @if ($document->locked_at)
                            since {{ $document->locked_at->diffForHumans() }}
                        @endif
                    </p>
                    <p class="mt-1 text-[var(--text-muted)]">
                        Editing is exclusive here on purpose: last-writer-wins on a controlled document is
                        data loss with an audit trail attached.
                        @if ($document->lockHasExpired())
                            This lock has lapsed, so it can be released.
                        @endif
                    </p>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if ($lockedByMe && $canCheckIn)
                    <form method="POST" action="{{ route('documents.check-in', $document) }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-secondary">Check in</button>
                    </form>
                @elseif ($canCheckIn)
                    <form method="POST" action="{{ route('documents.check-in', $document) }}"
                          x-data="{ armed: false }"
                          @submit="if (!armed) { $event.preventDefault(); armed = true; }">
                        @csrf
                        <input type="hidden" name="force" value="1">
                        <button type="submit" class="btn btn-sm" :class="armed ? 'btn-danger' : 'btn-secondary'">
                            <span x-text="armed ? 'Really break the lock?' : 'Break the lock'">Break the lock</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ============================================================ left column --}}
        <div class="space-y-6">

            {{-- ---------------------------------------------------- status --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status</h3>
                    @if ($document->status)
                        <x-badge :tone="$document->status->tone()">{{ $document->status->label }}</x-badge>
                    @endif
                </div>

                <div class="space-y-3 p-5">
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $document->confidentiality?->badgeClasses() }}">
                        <x-icon name="lock" class="h-3 w-3"/> {{ $document->confidentiality?->label() }}
                    </span>

                    <p class="text-sm text-[var(--text-muted)]">
                        @if ($current)
                            Current revision
                            <span class="font-mono font-medium text-[var(--text-strong)]">{{ $current->revision }}</span>
                            @if ($current->approved_at)
                                — approved {{ $current->approved_at->format('j M Y') }}
                                @if ($current->approver)
                                    by {{ $current->approver->name }}
                                @endif
                            @else
                                — not yet approved
                            @endif
                        @else
                            No revision has been raised yet. The number exists; it does not point at anything.
                        @endif
                    </p>

                    @if ($document->is_controlled)
                        <p class="text-xs text-[var(--text-muted)]">
                            Controlled document. Every copy taken from here is recorded.
                        </p>
                    @endif
                </div>

                {{-- ------------------------------------------------ submit / withdraw --}}
                @if ($canSubmit || $canWithdraw)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        @if ($instance?->isRunning() && $canWithdraw)
                            <p class="mb-3 text-sm text-[var(--text-muted)]">
                                This document is with its approvers. Withdrawing it cancels the run — the
                                decisions already recorded stay on the record.
                            </p>
                            <form method="POST" action="{{ route('documents.withdraw', $document) }}">
                                @csrf
                                <label for="reason" class="label">Why</label>
                                <textarea name="reason" id="reason" rows="2" class="textarea"
                                          placeholder="Recorded against the approval, and shown to everyone who was asked."></textarea>
                                @error('reason')
                                    <p class="error" role="alert">{{ $message }}</p>
                                @enderror
                                <button type="submit" class="btn btn-secondary btn-sm mt-3">
                                    <x-icon name="x" class="h-3.5 w-3.5"/> Withdraw from approval
                                </button>
                            </form>
                        @elseif ($canSubmit)
                            <p class="mb-3 text-sm text-[var(--text-muted)]">
                                Submitting sends the current revision down the route its register configures.
                                Who is asked, in what order, and how long they have is decided there — not here.
                            </p>
                            <form method="POST" action="{{ route('documents.submit', $document) }}">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm" @disabled($current === null)>
                                    <x-icon name="gavel" class="h-3.5 w-3.5"/> Submit for approval
                                </button>
                            </form>
                            @if ($current === null)
                                <p class="help">There is no revision to submit yet.</p>
                            @endif
                        @endif

                        @error('submit')
                            <p class="error mt-2" role="alert">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                {{-- ------------------------------------------------ check out --}}
                @if ($canCheckOut && ! $document->isLocked())
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('documents.check-out', $document) }}">
                            @csrf
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <x-icon name="lock" class="h-3.5 w-3.5"/> Check out for editing
                            </button>
                        </form>
                        <p class="help">
                            Takes the document out in your name so two people cannot overwrite each other.
                        </p>
                    </div>
                @endif
            </div>

            {{-- ---------------------------------------------------- particulars --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="divide-y divide-[var(--surface-border)] text-sm">
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Number</dt>
                        <dd class="text-right font-mono text-[var(--text-strong)]">{{ $document->number ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Register</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $document->category?->name ?? 'Unfiled' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Project</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $document->project?->name ?? 'None' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Department</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $document->orgUnit?->name ?? 'None' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Discipline</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $document->discipline?->name ?? 'None' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Owner</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $document->owner?->name ?? 'Nobody' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Raised by</dt>
                        <dd class="text-right text-[var(--text-body)]">
                            {{ $document->creator?->name ?? 'Unknown' }}
                            <span class="block text-xs text-[var(--text-muted)]">{{ $document->created_at?->format('j M Y') }}</span>
                        </dd>
                    </div>
                    @if ($document->next_review_on)
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Next review</dt>
                            <dd class="text-right text-[var(--text-body)]">{{ $document->next_review_on->format('j M Y') }}</dd>
                        </div>
                    @endif
                    @if ($document->retention_until)
                        <div class="flex justify-between gap-4 px-5 py-3">
                            <dt class="text-[var(--text-muted)]">Keep until</dt>
                            <dd class="text-right text-[var(--text-body)]">{{ $document->retention_until->format('j M Y') }}</dd>
                        </div>
                    @endif
                </dl>

                @if (! empty($document->tags))
                    <div class="flex flex-wrap gap-1.5 border-t border-[var(--surface-border)] px-5 py-4">
                        @foreach ($document->tags as $tag)
                            <span class="badge badge-neutral">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($instance)
                <x-workflow-progress :instance="$instance"/>
            @endif
        </div>

        {{-- ============================================================ right column --}}
        <div class="lg:col-span-2 space-y-6">

            @if ($document->description)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">About this document</h3>
                    </div>
                    <p class="whitespace-pre-line p-5 text-sm text-[var(--text-body)]">{{ $document->description }}</p>
                </div>
            @endif

            {{-- ---------------------------------------------------- revisions --}}
            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">Revisions</h3>
                    <span class="text-xs text-[var(--text-muted)]">{{ $versions->count() }} in all</span>
                </div>

                @if ($versions->isEmpty())
                    <x-empty-state
                        icon="file-text"
                        title="No revisions yet"
                        description="The number has been issued but nothing has been filed under it. Raise the first revision to give it content.">
                        @if ($canCreateVersion)
                            <x-slot:actions>
                                <a href="{{ route('documents.versions.create', $document) }}" class="btn btn-primary btn-sm">
                                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise the first revision
                                </a>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                @else
                    <div class="divide-y divide-[var(--surface-border)]">
                        @foreach ($versions as $version)
                            <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('documents.versions.show', [$document, $version]) }}"
                                           class="font-mono text-sm font-semibold text-[var(--text-strong)] hover:text-brand-600">
                                            Rev {{ $version->revision }}
                                        </a>

                                        @if ($version->is_current)
                                            <x-badge tone="brand">Current</x-badge>
                                        @endif

                                        @if ($version->isApproved())
                                            <x-badge tone="success">
                                                <x-icon name="check" class="h-3 w-3"/> Approved
                                            </x-badge>
                                        @elseif ($version->is_draft_series)
                                            <x-badge tone="warning">Draft series</x-badge>
                                        @endif

                                        @if ($version->isSuperseded())
                                            <x-badge tone="neutral">Superseded</x-badge>
                                        @endif

                                        @if ($version->status)
                                            <x-badge :tone="$version->status->tone()">{{ $version->status->label }}</x-badge>
                                        @endif
                                    </div>

                                    @if ($version->change_summary)
                                        <p class="mt-1 text-sm text-[var(--text-body)]">{{ $version->change_summary }}</p>
                                    @endif

                                    <p class="mt-1 text-xs text-[var(--text-muted)]">
                                        {{ $version->author?->name ?? 'Unknown' }} ·
                                        {{ $version->created_at?->format('j M Y, H:i') }}
                                        @if ($version->hasFile())
                                            · {{ $version->fileSizeForHumans() }}
                                        @elseif ($version->word_count)
                                            · {{ number_format((int) $version->word_count) }} words
                                        @endif
                                        @if ($version->isApproved())
                                            · approved by {{ $version->approver?->name ?? 'someone' }}
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-wrap items-center gap-2">
                                    @if ($canEdit && ! $version->isApproved() && ! $lockedByOther)
                                        <a href="{{ route('documents.versions.edit', [$document, $version]) }}"
                                           class="btn btn-sm btn-secondary">
                                            <x-icon name="pencil" class="h-3.5 w-3.5"/>
                                            {{ $document->isNative() ? 'Edit' : 'Replace file' }}
                                        </a>
                                    @endif

                                    @if ($canDownload && $version->hasFile())
                                        <a href="{{ route('documents.versions.download', [$document, $version]) }}"
                                           class="btn btn-sm btn-ghost">
                                            <x-icon name="download" class="h-3.5 w-3.5"/> File
                                        </a>
                                    @endif

                                    @if ($canDownload && $version->hasContent())
                                        <a href="{{ route('documents.versions.docx', [$document, $version]) }}"
                                           class="btn btn-sm btn-ghost">
                                            <x-icon name="download" class="h-3.5 w-3.5"/> Word
                                        </a>
                                    @endif

                                    @if ($canPrint)
                                        <a href="{{ route('documents.versions.pdf', [$document, $version]) }}"
                                           class="btn btn-sm btn-ghost" target="_blank" rel="noopener">
                                            <x-icon name="file-text" class="h-3.5 w-3.5"/> PDF
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                        An approved revision is never edited or deleted — it is superseded by the next one.
                        That is the whole point of the register.
                    </p>
                @endif
            </div>

            <x-timeline :entries="$timeline"
                        empty="Raising, editing, checking out, submitting, approving and every copy taken appear here, in order."/>
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$document" class="card mt-5"/>

</x-layouts.app>
