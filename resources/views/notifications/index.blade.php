{{--
    Your notifications.

    Every row here was authorized twice before it reached the page: once when it was written, against
    the policy for the record it is about, and again just now in the controller. Rows that failed the
    second check are gone rather than greyed out — a placeholder saying "something you cannot see"
    is still a disclosure that something exists (§68).

    The count of removed rows IS shown, once, at the bottom. That is not a contradiction: "some of
    your older notifications refer to records you no longer have access to" tells you about your own
    access changing, which you are entitled to know, and names nothing.

    Expects: $notifications (paginator), $visible (collection), $withheld, $unreadCount, $modules, $filters
--}}
<x-layouts.app title="Notifications">

    <x-page-header
        title="Notifications"
        description="What the system has told you, newest first.">
        <x-slot:actions>
            <a href="{{ route('notifications.preferences') }}" class="btn btn-secondary btn-sm">
                <x-icon name="sliders" class="h-3.5 w-3.5"/> Settings
            </a>
            @if ($unreadCount > 0)
                <form method="POST" action="{{ route('notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="check" class="h-3.5 w-3.5"/> Mark all read
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('notifications.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Anything in the text"/>
            </div>

            <div>
                <label for="module" class="label">About</label>
                <x-select name="module" :options="$modules" :selected="$filters['module']" placeholder="Everything"/>
            </div>

            <div>
                <label for="show" class="label">Show</label>
                <x-select name="show"
                          :options="['all' => 'Read and unread', 'unread' => 'Unread only']"
                          :selected="$filters['show'] ?: 'all'"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if (array_filter($filters))
                <a href="{{ route('notifications.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- the list --}}
    <div class="card overflow-hidden">
        @if ($visible->isEmpty())
            <x-empty-state
                icon="bell"
                title="Nothing here"
                description="You have no notifications matching this filter. The system only writes one when something happened to a record you can open — it does not tell you about work you have no part in."/>
        @else
            <ul class="divide-y divide-[var(--surface-border)]">
                @foreach ($visible as $notification)
                    <li class="flex items-start gap-3 px-4 py-3 {{ $notification->isRead() ? '' : 'bg-brand-50/60 dark:bg-brand-950/20' }}">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--surface-muted)] text-[var(--text-muted)]">
                            <x-icon :name="$notification->icon()" class="h-4 w-4"/>
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('notifications.open', $notification) }}"
                                   class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                                    {{ $notification->title }}
                                </a>

                                @if ($notification->urgency !== 'normal')
                                    <x-badge :tone="$notification->urgencyTone()">
                                        {{ ucfirst((string) $notification->urgency) }}
                                    </x-badge>
                                @endif

                                @unless ($notification->isRead())
                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-500" aria-label="Unread"></span>
                                @endunless
                            </div>

                            @if ($notification->body)
                                <p class="mt-0.5 text-sm text-[var(--text-muted)]">{{ $notification->body }}</p>
                            @endif

                            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-[var(--text-muted)]">
                                <span>{{ $notification->created_at?->diffForHumans() }}</span>
                                @if ($notification->causer)
                                    <span>· by {{ $notification->causer->name }}</span>
                                @endif
                                @if ($notification->project)
                                    <span>· {{ $notification->project->name }}</span>
                                @endif
                            </p>
                        </div>

                        @unless ($notification->isRead())
                            <form method="POST" action="{{ route('notifications.read', $notification) }}" class="shrink-0">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-ghost" title="Mark as read">
                                    <x-icon name="check" class="h-3.5 w-3.5"/>
                                </button>
                            </form>
                        @endunless
                    </li>
                @endforeach
            </ul>

            <div class="flex flex-col gap-2 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-[var(--text-muted)]">
                    Showing {{ $notifications->firstItem() }}–{{ $notifications->lastItem() }} of {{ $notifications->total() }}.
                    @if ($withheld > 0)
                        {{ $withheld }} on this page {{ $withheld === 1 ? 'refers' : 'refer' }} to records you can no
                        longer open and {{ $withheld === 1 ? 'is' : 'are' }} not shown.
                    @endif
                </p>
                @if ($notifications->hasPages())
                    {{ $notifications->links() }}
                @endif
            </div>
        @endif
    </div>

</x-layouts.app>
