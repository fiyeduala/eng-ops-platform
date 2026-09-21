{{--
    The landing page.

    Every figure here comes from the same ScopeCompiler the index pages use, so the number on the
    card and the number of rows behind it are the same number. See DashboardController.
--}}
<x-layouts.app title="Dashboard">

    <x-page-header
        title="Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening') }}, {{ str(auth()->user()->name)->before(' ') }}"
        :description="$me?->position?->title
            ? $me->position->title.($me->orgUnit ? ' · '.$me->orgUnit->name : '')
            : 'Welcome back.'"/>

    {{--
        The personal board, above the structural cards.

        Whatever the company has decided everybody should notice goes first, because it is the part
        that changes. Each tile was already decided for this reader by WidgetComposer — a tile
        whose register they cannot read never arrived — so there is nothing to check here.
    --}}
    @if ($tiles !== [])
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-12">
            @foreach ($tiles as $tile)
                <x-widget :tile="$tile"/>
            @endforeach
        </div>
    @endif

    @if ($cards)
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                <a href="{{ $card['url'] }}" class="card flex items-center gap-4 p-5 transition hover:border-brand-300">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
                        <x-icon :name="$card['icon']" class="h-5 w-5"/>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-2xl font-semibold tracking-tight text-[var(--text-strong)]">{{ number_format($card['value']) }}</span>
                        <span class="block truncate text-sm text-[var(--text-muted)]">{{ $card['label'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ your record --}}
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h3 class="card-title">Your record</h3>
                <a href="{{ route('profile.show') }}" class="btn btn-sm btn-secondary">
                    <x-icon name="user" class="h-3.5 w-3.5"/> View profile
                </a>
            </div>

            @if ($me)
                <dl class="grid gap-x-6 gap-y-4 p-5 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Employee number</dt>
                        <dd class="mt-1 font-mono text-sm text-[var(--text-strong)]">{{ $me->employee_no ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Position</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $me->position?->title ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Unit</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $me->orgUnit?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Reports to</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $me->supervisor?->fullName() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Base location</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $me->location?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Joined</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $me->hired_on?->format('j M Y') ?? '—' }}</dd>
                    </div>
                </dl>
            @else
                <x-empty-state
                    icon="user"
                    title="No personnel record is linked to your account"
                    description="Your sign-in works, but nothing connects it to an employee record yet. An administrator links the two on the employee’s page."/>
            @endif
        </div>

        {{-- ------------------------------------------------------------ access --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Your access</h3>
            </div>

            <div class="p-5">
                @forelse ($roles as $role)
                    <div class="mb-3 last:mb-0">
                        <div class="flex items-center gap-2">
                            <x-icon name="key" class="h-3.5 w-3.5 text-[var(--text-muted)]"/>
                            <span class="text-sm font-medium text-[var(--text-strong)]">{{ $role->name }}</span>
                        </div>
                        @if ($role->pivot->scope_type === 'org_unit' && $role->pivot->scope_id)
                            <p class="mt-0.5 pl-5 text-xs text-[var(--text-muted)]">Limited to one unit</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-[var(--text-muted)]">
                        You hold no roles yet, so most of the system is not open to you. An administrator assigns them.
                    </p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- timeline --}}
    <div class="card mt-6">
        <div class="card-header">
            <h3 class="card-title">Your recent activity</h3>
        </div>

        @if ($activity->isEmpty())
            <x-empty-state
                icon="clock"
                title="Nothing yet"
                description="Actions you take are recorded here as you work."/>
        @else
            <ul class="p-5">
                @foreach ($activity as $entry)
                    <li class="relative flex gap-3 pb-5 last:pb-0">
                        @unless ($loop->last)
                            <span class="absolute left-[5px] top-3 h-full w-px bg-[var(--surface-border)]" aria-hidden="true"></span>
                        @endunless
                        <span class="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $entry->dotClasses() }}"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-[var(--text-body)]">{{ $entry->description ?? $entry->verb }}</p>
                            <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->diffForHumans() }}</time>
                            </p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

</x-layouts.app>
