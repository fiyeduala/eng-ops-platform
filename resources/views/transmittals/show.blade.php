{{--
    One transmittal.

    The schedule of documents is the record, so it is the largest thing on the page and it names
    REVISIONS. Six months into a dispute the question is never "did we send them the general
    arrangement drawing", it is "which revision, on what date, and did they confirm receipt" — and a
    row that linked only to the document would answer with whatever is current today.

    THE ACTIONS ARE GATED SERVER-SIDE AND DRAWN FROM THAT. Every button here is behind a $can flag
    the policy produced; the routes re-authorize regardless, because a button that is not drawn is
    not a button that cannot be pressed (§6, §84).

    Expects: $transmittal, $items, $timeline, $canEdit, $canAddItems, $canIssue, $canAcknowledge,
             $canCancel, $canCoverSheet, $canDelete
--}}
<x-layouts.app :title="$transmittal->displayName()">

    <x-page-header
        :title="$transmittal->subject"
        :description="$transmittal->number.' · '.$transmittal->purposeLabel()">
        <x-slot:actions>
            @if ($canCoverSheet)
                <a href="{{ route('transmittals.cover-sheet', $transmittal) }}" target="_blank" rel="noopener"
                   class="btn btn-secondary btn-sm">
                    <x-icon name="download" class="h-3.5 w-3.5"/> Cover sheet
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('transmittals.edit', $transmittal) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canIssue && $transmittal->isDraft())
                <form method="POST" action="{{ route('transmittals.issue', $transmittal) }}" class="inline"
                      x-data="{ armed: false }">
                    @csrf
                    <button type="button" class="btn btn-primary btn-sm" x-show="! armed" @click="armed = true">
                        <x-icon name="check" class="h-3.5 w-3.5"/> Issue
                    </button>
                    <button type="submit" class="btn btn-danger btn-sm" x-show="armed" x-cloak>
                        Issue it — this cannot be undone
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- state banner --}}
    @if ($transmittal->isDraft())
        <div class="mb-5 card flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <div class="text-sm text-[var(--text-body)]">
                <p class="font-medium">This is a draft. Nothing has been sent.</p>
                <p class="mt-1 text-[var(--text-muted)]">
                    Only you and the people who can issue transmittals can see it. Issuing is a
                    one-way door: after it, the transmittal cannot be edited or deleted by anyone,
                    because it becomes a statement about what was sent that the other party holds
                    their own copy of. A mistake is corrected by cancelling and re-issuing.
                </p>
            </div>
        </div>
    @elseif ($transmittal->isOverdue())
        <div class="mb-5 card flex items-start gap-3 border-l-4 border-l-rose-400 p-4">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-rose-600"/>
            <p class="text-sm text-[var(--text-body)]">
                A response was due {{ $transmittal->due_response_on->format('j M Y') }} and no receipt
                has been recorded.
            </p>
        </div>
    @elseif ($transmittal->awaitingAcknowledgement())
        <div class="mb-5 card flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <p class="text-sm text-[var(--text-body)]">
                Issued {{ $transmittal->issued_at->diffForHumans() }}, awaiting confirmation of receipt.
            </p>
        </div>
    @endif

    @error('issue')
        <div class="mb-5 card border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ the schedule --}}
        <div class="space-y-5 lg:col-span-2">

            <div class="card overflow-hidden">
                <div class="card-header flex items-center justify-between">
                    <h3 class="card-title">Schedule of documents</h3>
                    @if ($canAddItems && $transmittal->isDraft())
                        <a href="{{ route('transmittals.picker', $transmittal) }}" class="btn btn-secondary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Add revisions
                        </a>
                    @endif
                </div>

                @error('items')
                    <p class="error px-5 pt-4" role="alert">{{ $message }}</p>
                @enderror
                @error('document_version_id')
                    <p class="error px-5 pt-4" role="alert">{{ $message }}</p>
                @enderror

                @if ($items->isEmpty())
                    <x-empty-state
                        icon="file-text"
                        title="Nothing on it yet"
                        description="A transmittal cannot be issued with no documents on it — there would be nothing for the record to be a record of.">
                        @if ($canAddItems && $transmittal->isDraft())
                            <x-slot:actions>
                                <a href="{{ route('transmittals.picker', $transmittal) }}" class="btn btn-primary btn-sm">
                                    Add revisions
                                </a>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th class="w-10">#</th>
                                    <th>Document</th>
                                    <th>Rev</th>
                                    <th>Media</th>
                                    <th>Copies</th>
                                    <th>Remarks</th>
                                    @if ($canAddItems && $transmittal->isDraft())
                                        <th class="w-10"><span class="sr-only">Remove</span></th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $index => $item)
                                    @php $version = $item->documentVersion; @endphp
                                    <tr>
                                        <td class="text-[var(--text-muted)]">{{ $index + 1 }}</td>

                                        <td>
                                            @if ($version?->document)
                                                <a href="{{ route('documents.show', $version->document) }}"
                                                   class="font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                                                    {{ $version->document->number }}
                                                </a>
                                                <span class="block text-xs text-[var(--text-muted)]">
                                                    {{ $version->document->title }}
                                                    @if ($version->document->category)
                                                        · {{ $version->document->category->name }}
                                                    @endif
                                                </span>
                                            @else
                                                <span class="text-[var(--text-muted)]">Revision no longer available</span>
                                            @endif
                                        </td>

                                        <td class="font-mono text-sm text-[var(--text-body)]">
                                            {{ $version?->revision ?? '—' }}
                                            @if ($version && ! $version->isApproved())
                                                <x-badge tone="warning">Unapproved</x-badge>
                                            @endif
                                        </td>

                                        <td class="text-[var(--text-muted)]">{{ $item->mediaLabel() }}</td>
                                        <td class="text-[var(--text-muted)]">{{ $item->copies }}</td>
                                        <td class="max-w-xs text-xs text-[var(--text-muted)]">{{ $item->remarks }}</td>

                                        @if ($canAddItems && $transmittal->isDraft())
                                            <td>
                                                <x-delete-form
                                                    :action="route('transmittals.items.destroy', [$transmittal, $item])"
                                                    label="Remove"
                                                    confirm="Remove this revision from the transmittal?"/>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if ($transmittal->cover_note)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Covering note</h3>
                    </div>
                    <div class="whitespace-pre-line p-5 text-sm text-[var(--text-body)]">{{ $transmittal->cover_note }}</div>
                </div>
            @endif

            {{-- ------------------------------------------------------ acknowledgement --}}
            @if ($transmittal->isIssued())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Receipt</h3>
                    </div>

                    <div class="p-5">
                        @if ($transmittal->isAcknowledged())
                            <p class="text-sm text-[var(--text-body)]">
                                Confirmed
                                @if ($transmittal->acknowledged_by_name)
                                    by <span class="font-medium">{{ $transmittal->acknowledged_by_name }}</span>
                                @endif
                                on {{ $transmittal->acknowledged_at->format('j M Y') }}.
                            </p>
                            @if ($transmittal->acknowledgement_reference)
                                <p class="mt-1 text-xs text-[var(--text-muted)]">
                                    Reference: {{ $transmittal->acknowledgement_reference }}
                                </p>
                            @endif
                        @elseif ($canAcknowledge)
                            <p class="mb-4 text-sm text-[var(--text-muted)]">
                                Recorded by somebody here on behalf of somebody outside. The
                                recipient has no account and never clicks anything — this is a note
                                that a reply or a signed receipt was actually in hand.
                            </p>

                            <form method="POST" action="{{ route('transmittals.acknowledge', $transmittal) }}"
                                  class="space-y-4">
                                @csrf

                                <x-field label="Confirmed by" name="acknowledged_by_name"
                                         help="The name of the person on the other side who confirmed it.">
                                    <x-input name="acknowledged_by_name" maxlength="200"/>
                                </x-field>

                                <x-field label="Reference" name="acknowledgement_reference"
                                         help="Their email subject, receipt number or portal reference — whatever makes the confirmation findable later.">
                                    <x-input name="acknowledgement_reference" maxlength="200"/>
                                </x-field>

                                <button type="submit" class="btn btn-primary btn-sm">Record receipt</button>
                            </form>
                        @else
                            <p class="text-sm text-[var(--text-muted)]">
                                No receipt has been recorded yet.
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            <x-timeline :entries="$timeline"
                        title="History of this transmittal"
                        empty="Nothing has happened since it was raised."/>
        </div>

        {{-- ------------------------------------------------------------ particulars --}}
        <div class="space-y-5">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Addressed to</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Organisation</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            @include("partials.party-link", ["party" => $transmittal->toParty, "name" => $transmittal->to_name])
                        </dd>
                    </div>

                    @if ($transmittal->to_attention)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Attention</dt>
                            <dd class="mt-0.5 text-[var(--text-strong)]">{{ $transmittal->to_attention }}</dd>
                        </div>
                    @endif

                    @if ($transmittal->to_email)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Email</dt>
                            <dd class="mt-0.5 text-[var(--text-strong)]">{{ $transmittal->to_email }}</dd>
                        </div>
                    @endif

                    @if ($transmittal->to_address)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Address</dt>
                            <dd class="mt-0.5 whitespace-pre-line text-[var(--text-strong)]">{{ $transmittal->to_address }}</dd>
                        </div>
                    @endif

                    @if ($transmittal->transportLabel())
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Sent by</dt>
                            <dd class="mt-0.5 text-[var(--text-strong)]">{{ $transmittal->transportLabel() }}</dd>
                        </div>
                    @endif
                </dl>

                <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                    Nothing is emailed to the recipient from here. They have no account and no policy
                    governs them, so sending them a link would be handing an unauthenticated URL to a
                    party this platform knows nothing about. Send the cover sheet yourself.
                </p>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Number</dt>
                        <dd class="mt-0.5 font-mono text-[var(--text-strong)]">{{ $transmittal->number }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Purpose</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">{{ $transmittal->purposeLabel() }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Confidentiality</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">{{ $transmittal->confidentiality?->label() }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Project</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            @if ($transmittal->project)
                                <a href="{{ route('projects.show', $transmittal->project) }}" class="hover:text-brand-600">
                                    {{ $transmittal->project->displayName() }}
                                </a>
                            @else
                                Not against a project
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Issued</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            @if ($transmittal->isIssued())
                                {{ $transmittal->issued_at->format('j M Y, H:i') }}
                                @if ($transmittal->issuer)
                                    <span class="block text-xs text-[var(--text-muted)]">by {{ $transmittal->issuer->name }}</span>
                                @endif
                            @else
                                Not yet
                            @endif
                        </dd>
                    </div>

                    @if ($transmittal->due_response_on)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Response due</dt>
                            <dd class="mt-0.5 text-[var(--text-strong)]">
                                {{ $transmittal->due_response_on->format('j M Y') }}
                            </dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Raised</dt>
                        <dd class="mt-0.5 text-[var(--text-strong)]">
                            {{ $transmittal->created_at?->format('j M Y') ?? '—' }}
                            @if ($transmittal->creator)
                                <span class="block text-xs text-[var(--text-muted)]">by {{ $transmittal->creator->name }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- ------------------------------------------------------ cancel --}}
            @if ($canCancel && $transmittal->isIssued())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Cancel this transmittal</h3>
                    </div>

                    <div class="p-5" x-data="{ open: false }">
                        <p class="text-sm text-[var(--text-muted)]">
                            For a transmittal that went out wrong. It stays in the register, marked
                            cancelled with the reason recorded — the other side has a copy, so
                            pretending it never happened would be the one thing worse than the
                            mistake.
                        </p>

                        <button type="button" class="btn btn-secondary btn-sm mt-3" x-show="! open" @click="open = true">
                            Cancel it
                        </button>

                        <form method="POST" action="{{ route('transmittals.cancel', $transmittal) }}"
                              class="mt-3 space-y-3" x-show="open" x-cloak>
                            @csrf

                            <x-field label="Reason" name="reason" required
                                     help="Recorded against the transmittal and shown in its history.">
                                <x-textarea name="reason" rows="3"/>
                            </x-field>

                            <div class="flex items-center gap-2">
                                <button type="submit" class="btn btn-danger btn-sm">Cancel the transmittal</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="open = false">Never mind</button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            {{-- ------------------------------------------------------ delete --}}
            @if ($canDelete)
                <div class="card border-red-200">
                    <div class="card-header">
                        <h3 class="card-title">Delete this draft</h3>
                    </div>

                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            Only ever possible while it is a draft. Nothing has been sent, so nothing
                            is lost but the list itself.
                        </p>

                        <x-delete-form :action="route('transmittals.destroy', $transmittal)"
                                       label="Delete this draft"
                                       confirm="Delete this draft transmittal? Nothing has been sent, so nothing else goes with it."/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
