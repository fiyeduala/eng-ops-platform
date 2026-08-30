{{--
    The noticeboard.

    Two ideas are live here and mixing them up is the whole risk in this module:

      MAY I see it   — the scope-compiled reach, plus the draft curtain on `published_at`. Applied
                       first, always, and never optional.
      IS it for me   — the addressing pair (project / department), which decides whose board a
                       notice appears on. Applied second, as a filter.

    So "For me" is a narrower list than "All live notices", and the wider one is not a leak: the
    baseline role grants `announcement.view` across the organization on purpose, because a
    noticeboard everybody can consult is what a noticeboard is. Anything that must be kept from
    some of the people who can see the board is a memo, filed in the wrong module.

    Expects: $announcements, $statuses, $projects, $views, $filters, $canCreate
--}}
<x-layouts.app title="Announcements">

    <x-page-header
        title="Announcements"
        description="Company notices. Where a notice is pinned decides whose board it lands on — not who is allowed to read it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('announcements.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New notice
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('announcements.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Title or notice number"/>
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
                <label for="project" class="label">Project</label>
                <x-select name="project" :options="$projects" :selected="$filters['project']" placeholder="Any project"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if (array_filter($filters, fn ($v, $k) => $v && ! ($k === 'view' && $v === 'current'), ARRAY_FILTER_USE_BOTH))
                <a href="{{ route('announcements.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    @if ($announcements->isEmpty())
        <div class="card">
            <x-empty-state
                icon="megaphone"
                title="Nothing on the board"
                description="Either nothing matches this filter, or the notices it would match are aimed at another part of the company. Try “All live notices” to widen it — the board is not private, only sorted."/>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($announcements as $announcement)
                @php
                    $tone = match ($announcement->urgency) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        default => 'neutral',
                    };
                @endphp
                <article class="card p-5 {{ $announcement->is_pinned ? 'border-l-4 border-l-brand-500' : '' }}">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <a href="{{ route('announcements.show', $announcement) }}"
                               class="text-sm font-semibold text-[var(--text-strong)] hover:text-brand-600">
                                {{ $announcement->title }}
                            </a>

                            <p class="mt-0.5 font-mono text-xs text-[var(--text-muted)]">{{ $announcement->number }}</p>

                            <p class="mt-2 line-clamp-2 text-sm text-[var(--text-muted)]">
                                {{ \Illuminate\Support\Str::limit((string) $announcement->body, 220) }}
                            </p>

                            <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-[var(--text-muted)]">
                                <span>{{ $announcement->audienceLabel() }}</span>
                                <span>·</span>
                                <span>
                                    @if ($announcement->isPublished())
                                        {{ $announcement->published_at->format('j M Y') }}
                                        @if ($announcement->publisher)
                                            by {{ $announcement->publisher->name }}
                                        @endif
                                    @else
                                        Drafted by {{ $announcement->creator?->name ?? 'someone' }}
                                    @endif
                                </span>
                                @if ($announcement->expires_on)
                                    <span>·</span>
                                    <span>{{ $announcement->hasExpired() ? 'Expired' : 'Until' }} {{ $announcement->expires_on->format('j M Y') }}</span>
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            @if ($announcement->is_pinned)
                                <x-badge tone="brand"><x-icon name="pin" class="h-3 w-3"/> Pinned</x-badge>
                            @endif
                            @if ($announcement->urgency !== 'normal')
                                <x-badge :tone="$tone">{{ ucfirst((string) $announcement->urgency) }}</x-badge>
                            @endif
                            @if ($announcement->isDraft())
                                <x-badge tone="warning"><x-icon name="pencil" class="h-3 w-3"/> Draft</x-badge>
                            @elseif ($announcement->hasExpired())
                                <x-badge tone="neutral">Expired</x-badge>
                            @endif
                            @if ($announcement->status)
                                <x-badge :tone="$announcement->status->color ?? 'neutral'">{{ $announcement->status->label }}</x-badge>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-[var(--text-muted)]">
                Showing {{ $announcements->firstItem() }}–{{ $announcements->lastItem() }} of {{ $announcements->total() }}.
            </p>
            @if ($announcements->hasPages())
                {{ $announcements->links() }}
            @endif
        </div>
    @endif

</x-layouts.app>
