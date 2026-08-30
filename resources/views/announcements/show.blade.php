{{--
    One notice.

    Publishing, unpublishing and archiving all arrive at the same endpoint under the same permission,
    because all three are the one act of deciding what the company is currently being told. Which of
    them is legal right now is answered by the policy from the record's state, and which are offered
    at all is decided by the configured whitelist — neither is a condition in this file.

    Expects: $announcement, $timeline, $transitions, $canEdit, $canPublish, $canDelete
--}}
@php
    $transitionRules = $transitions->mapWithKeys(fn ($t) => [
        (string) $t->to_status_id => (bool) $t->requires_comment,
    ])->all();

    $urgencyTone = match ($announcement->urgency) {
        'urgent' => 'danger',
        'high' => 'warning',
        default => 'neutral',
    };
@endphp

<x-layouts.app :title="$announcement->displayName()">

    <x-page-header
        :title="$announcement->title"
        :description="$announcement->number.' · for '.$announcement->audienceLabel()">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('announcements.edit', $announcement) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canDelete)
                <x-delete-form :action="route('announcements.destroy', $announcement)"
                               label="Delete"
                               confirm="Delete {{ $announcement->number }}? Only an unpublished draft can be deleted."/>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($announcement->isDraft())
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="pencil" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <p class="text-sm text-[var(--text-body)]">
                This notice is a draft. It is not on anybody's board and nothing has been sent.
            </p>
        </div>
    @elseif ($announcement->hasExpired())
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-[var(--surface-border)] p-4">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p class="text-sm text-[var(--text-body)]">
                This notice expired on {{ $announcement->expires_on->format('j M Y') }}. It has come off the
                board but stays in the archive, because what the company was told is worth keeping.
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ summary --}}
        <div class="space-y-6">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status</h3>
                    @if ($announcement->status)
                        <x-badge :tone="$announcement->status->color ?? 'neutral'">{{ $announcement->status->label }}</x-badge>
                    @endif
                </div>

                <div class="p-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <x-badge :tone="$urgencyTone">{{ ucfirst((string) $announcement->urgency) }} urgency</x-badge>
                        @if ($announcement->is_pinned)
                            <x-badge tone="brand"><x-icon name="pin" class="h-3 w-3"/> Pinned</x-badge>
                        @endif
                    </div>

                    <p class="mt-4 text-sm text-[var(--text-muted)]">
                        @if ($announcement->isPublished())
                            Published {{ $announcement->published_at->format('j M Y, H:i') }}
                            @if ($announcement->publisher)
                                by {{ $announcement->publisher->name }}
                            @endif
                        @else
                            Not published.
                        @endif
                    </p>
                </div>

                @if ($canPublish)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        @if ($transitions->isEmpty())
                            <p class="text-sm text-[var(--text-muted)]">
                                There is nowhere for this notice to move from here.
                            </p>
                        @else
                            <form method="POST" action="{{ route('announcements.status', $announcement) }}"
                                  x-data="{ target: '', rules: @js($transitionRules) }">
                                @csrf
                                <label for="status_id" class="label">Move this notice to</label>
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
                                              placeholder="Recorded on the timeline."></textarea>
                                </div>

                                @error('status_id')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror

                                <button type="submit" class="btn btn-primary btn-sm mt-3" x-bind:disabled="target === ''">
                                    <x-icon name="check" class="h-3.5 w-3.5"/> Apply
                                </button>
                            </form>

                            @if ($announcement->isDraft())
                                <p class="mt-3 text-xs text-[var(--text-muted)]">
                                    Publishing notifies the audience below. An urgent notice reaches them even
                                    inside their quiet hours.
                                </p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Audience</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $announcement->audienceLabel() }}
                            <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                Where it is routed. Not a restriction on who may read it.
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Department</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $announcement->orgUnit?->name ?? 'Everyone' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Project</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($announcement->project)
                                <a href="{{ route('projects.show', $announcement->project) }}" class="text-brand-600 hover:underline">
                                    {{ $announcement->project->name }}
                                </a>
                            @else
                                Not project-specific
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Shows until</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $announcement->expires_on?->format('j M Y') ?? 'No expiry' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Written by</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $announcement->creator?->name ?? 'System' }}
                            <span class="block text-xs text-[var(--text-muted)]">{{ $announcement->created_at?->format('j M Y') }}</span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- ------------------------------------------------------------ detail --}}
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Notice</h3>
                    <span class="font-mono text-xs text-[var(--text-muted)]">{{ $announcement->number }}</span>
                </div>
                <div class="p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $announcement->body }}</p>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Activity</h3>
                </div>

                @if ($timeline->isEmpty())
                    <x-empty-state
                        icon="clock"
                        title="Nothing recorded yet"
                        description="Drafting, publishing, corrections and withdrawal appear here in order."/>
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
