{{--
    One memo.

    Opening this page is what "read" means — the receipt is stamped by the controller on the way in,
    not by a button, because a receipt people have to press measures diligence rather than delivery.

    Every control here was drawn from a policy answer and every one of them is asked again when
    pressed (§84). Three of them are separate authorities on purpose:

      - EDIT closes the moment the memo is issued. A memo is a statement of what was communicated
        and when; correcting it in place would rewrite that under everybody who already acted on it.
      - ADDRESSING stays open after issue — a late addition is granted the same access the original
        recipients got — but UNADDRESSING does not, because removing a name from an issued memo
        falsifies the record of who was told.
      - ISSUING and WITHDRAWING are the same permission and never both available at once.

    Expects: $memo, $ownReceipt, $timeline, $transitions, $candidates,
             $canEdit, $canAddress, $canUnaddress, $canIssue, $canWithdraw, $canDelete
--}}
@php
    $transitionRules = $transitions->mapWithKeys(fn ($t) => [
        (string) $t->to_status_id => (bool) $t->requires_comment,
    ])->all();

    $mayMove = $memo->isDraft() ? $canIssue : $canWithdraw;

    $readCount = $memo->recipients->whereNotNull('read_at')->count();
@endphp

<x-layouts.app :title="$memo->displayName()">

    <x-page-header
        :title="$memo->subject"
        :description="$memo->number.($memo->project ? ' · '.$memo->project->name : '')">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('memos.edit', $memo) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canDelete)
                <x-delete-form :action="route('memos.destroy', $memo)"
                               label="Delete"
                               confirm="Delete {{ $memo->number }}? Only an unissued draft can be deleted."/>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($memo->isDraft())
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="pencil" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <p class="text-sm text-[var(--text-body)]">
                This is a draft. Nobody on the distribution list can see it, and nothing has been sent.
                Issuing it is what puts it in front of them — and closes it to further editing.
            </p>
        </div>
    @elseif ($memo->withdrawn_reason)
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-rose-400 p-4">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-rose-600"/>
            <div class="text-sm text-[var(--text-body)]">
                <p class="font-medium">This memo has been withdrawn.</p>
                <p class="mt-1 text-[var(--text-muted)]">{{ $memo->withdrawn_reason }}</p>
                <p class="mt-1 text-xs text-[var(--text-muted)]">
                    It stays on the record, because people acted on it while it stood.
                </p>
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ summary --}}
        <div class="space-y-6">

            {{-- ---------------------------------------------------- status --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status</h3>
                    @if ($memo->status)
                        <x-badge :tone="$memo->status->color ?? 'neutral'">{{ $memo->status->label }}</x-badge>
                    @endif
                </div>

                <div class="p-5">
                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $memo->confidentiality?->badgeClasses() }}">
                        <x-icon name="lock" class="h-3 w-3"/> {{ $memo->confidentiality?->label() }}
                    </span>

                    @if ($memo->isIssued())
                        <p class="mt-4 text-sm text-[var(--text-muted)]">
                            Issued {{ $memo->issued_at->format('j M Y, H:i') }}
                            @if ($memo->issuer)
                                by {{ $memo->issuer->name }}
                            @endif
                        </p>
                    @else
                        <p class="mt-4 text-sm text-[var(--text-muted)]">Not issued.</p>
                    @endif
                </div>

                @if ($mayMove)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        @if ($transitions->isEmpty())
                            <p class="text-sm text-[var(--text-muted)]">
                                There is nowhere for this memo to move from here — either it has reached a
                                final status, or the moves that exist need a permission you do not hold.
                            </p>
                        @else
                            <form method="POST" action="{{ route('memos.status', $memo) }}"
                                  x-data="{ target: '', rules: @js($transitionRules) }">
                                @csrf
                                <label for="status_id" class="label">
                                    {{ $memo->isDraft() ? 'Issue this memo as' : 'Move this memo to' }}
                                </label>
                                <select name="status_id" id="status_id" x-model="target" class="select" required>
                                    <option value="">Choose a status…</option>
                                    @foreach ($transitions as $transition)
                                        <option value="{{ $transition->to_status_id }}">{{ $transition->to?->label }}</option>
                                    @endforeach
                                </select>

                                <div class="mt-3" x-show="target !== ''" x-cloak>
                                    <label for="comment" class="label">
                                        Reason
                                        <span x-show="! rules[target]" class="font-normal text-[var(--text-muted)]">(optional)</span>
                                    </label>
                                    <textarea name="comment" id="comment" rows="2" class="textarea"
                                              :required="rules[target]"
                                              placeholder="Recorded on the timeline, and used as the withdrawal reason."></textarea>
                                </div>

                                @error('status_id')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror

                                <button type="submit" class="btn btn-primary btn-sm mt-3" x-bind:disabled="target === ''">
                                    <x-icon name="check" class="h-3.5 w-3.5"/> Apply
                                </button>
                            </form>

                            @if ($memo->isDraft())
                                <p class="mt-3 text-xs text-[var(--text-muted)]">
                                    Issuing notifies everybody on the distribution list and, for a restricted
                                    memo, writes each of them an explicit grant. It cannot be issued to nobody.
                                </p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>

            {{-- ---------------------------------------------------- particulars --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Project</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($memo->project)
                                <a href="{{ route('projects.show', $memo->project) }}" class="text-brand-600 hover:underline">
                                    {{ $memo->project->name }}
                                </a>
                            @else
                                Not filed against a project
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Department</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $memo->orgUnit?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Written by</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $memo->creator?->name ?? 'System' }}
                            <span class="block text-xs text-[var(--text-muted)]">{{ $memo->created_at?->format('j M Y') }}</span>
                        </dd>
                    </div>
                    @if ($ownReceipt)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Your receipt</dt>
                            <dd class="mt-1 text-sm text-[var(--text-strong)]">
                                @if ($ownReceipt->isRead())
                                    Opened {{ $ownReceipt->read_at->diffForHumans() }}
                                @else
                                    Recorded as you read this
                                @endif
                                <span class="block text-xs text-[var(--text-muted)]">
                                    You are on the distribution list ({{ $ownReceipt->kind }}).
                                </span>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>
        </div>

        {{-- ------------------------------------------------------------ detail --}}
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Memorandum</h3>
                    <span class="font-mono text-xs text-[var(--text-muted)]">{{ $memo->number }}</span>
                </div>
                <div class="p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $memo->body }}</p>
                </div>
            </div>

            {{-- ---------------------------------------------------- distribution --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Distribution</h3>
                    @if ($memo->recipients->isNotEmpty())
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ $readCount }} of {{ $memo->recipients->count() }} opened
                        </span>
                    @endif
                </div>

                @if ($memo->recipients->isEmpty())
                    <x-empty-state
                        icon="users"
                        title="Addressed to nobody yet"
                        description="A memo cannot be issued to an empty list. Adding somebody here is what gives them sight of it — including for a restricted memo, where it writes them an explicit grant."/>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($memo->recipients as $recipient)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-[var(--text-strong)]">
                                        {{ $recipient->user?->name ?? 'Unknown account' }}
                                    </p>
                                    <p class="text-xs text-[var(--text-muted)]">
                                        {{ strtoupper((string) $recipient->kind) }}
                                        @if ($recipient->isRead())
                                            · opened {{ $recipient->read_at->diffForHumans() }}
                                        @elseif ($memo->isIssued())
                                            · not yet opened
                                        @endif
                                    </p>
                                </div>

                                @if ($canUnaddress)
                                    <x-delete-form :action="route('memos.recipients.destroy', [$memo, $recipient])"
                                                   label=""
                                                   confirm="Remove?"/>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canAddress)
                    @if ($candidates === [])
                        <p class="border-t border-[var(--surface-border)] px-5 py-4 text-sm text-[var(--text-muted)]">
                            There is nobody left you can add. Addressing somebody gives them sight of this
                            memo, so the list is limited to accounts your own access reaches.
                        </p>
                    @else
                        <form method="POST" action="{{ route('memos.recipients.store', $memo) }}"
                              class="space-y-3 border-t border-[var(--surface-border)] p-4">
                            @csrf

                            <div>
                                <label for="recipients" class="label">Add to the distribution</label>
                                <select name="recipients[]" id="recipients" multiple size="6" class="select h-auto">
                                    @foreach ($candidates as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('recipients')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div class="sm:w-48">
                                    <label for="kind" class="label">As</label>
                                    <x-select name="kind" :options="['to' => 'To', 'cc' => 'Copy']" selected="to"/>
                                </div>
                                <button type="submit" class="btn btn-secondary">
                                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add
                                </button>
                            </div>

                            @if ($memo->isIssued())
                                <p class="text-xs text-[var(--text-muted)]">
                                    This memo has already gone out. Somebody added now gets the same access the
                                    original recipients got, but is not notified again — tell them yourself.
                                </p>
                            @endif
                        </form>
                    @endif
                @endif
            </div>

            {{-- ---------------------------------------------------- timeline --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Activity</h3>
                </div>

                @if ($timeline->isEmpty())
                    <x-empty-state
                        icon="clock"
                        title="Nothing recorded yet"
                        description="Drafting, addressing, issuing and withdrawal appear here in order. Read receipts deliberately do not — they are on the distribution list above, and one row per person per open would bury the memo's real history."/>
                @else
                    <ul class="p-5">
                        @foreach ($timeline as $entry)
                            <li class="relative flex gap-3 pb-5 last:pb-0">
                                @unless ($loop->last)
                                    <span class="absolute left-[5px] top-3 h-full w-px bg-[var(--surface-border)]" aria-hidden="true"></span>
                                @endunless
                                <span class="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $entry->dotClasses() }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-[var(--text-body)]">{{ $entry->description ?? $entry->verb }}</p>
                                    @if (! empty($entry->properties['comment']))
                                        <p class="mt-1 border-l-2 border-[var(--surface-border)] pl-3 text-sm text-[var(--text-muted)]">
                                            {{ $entry->properties['comment'] }}
                                        </p>
                                    @endif
                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                        {{ $entry->actorName() }} ·
                                        <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->diffForHumans() }}</time>
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
