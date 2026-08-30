{{--
    The calendar.

    A view over records that already exist, never a module of its own. Every entry on this page was
    put here by the same scope compiler that governs its own index, asked with its own ability —
    `meeting.view` for meetings, `task.view` for tasks, `project.view` for milestones. There is no
    calendar permission, so this screen can never become a second, weaker door onto anything (§90).

    That is also why the legend below says which sources are contributing. A calendar that silently
    omits a whole category is worse than one that says "you do not have sight of tasks": the first
    looks empty, the second is honest.

    Expects: $from, $to, $previous, $next, $today, $days, $entries, $sources
--}}
@php
    $gridStart = $from->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY);
    $gridEnd = $to->copy()->endOfWeek(\Illuminate\Support\Carbon::SUNDAY);

    $cells = [];
    for ($cursor = $gridStart->copy(); $cursor->lte($gridEnd); $cursor->addDay()) {
        $cells[] = $cursor->copy();
    }
    $weeks = array_chunk($cells, 7);

    $dot = [
        'meeting' => 'bg-brand-500',
        'task' => 'bg-amber-500',
        'project' => 'bg-slate-400',
    ];

    // source key => [entry type, label]. The entry type is what carries the colour, so the legend
    // and the cells can never drift apart.
    $sourceLabels = [
        'meetings' => ['meeting', 'Meetings'],
        'tasks' => ['task', 'Task due dates'],
        'projects' => ['project', 'Project milestones'],
    ];
@endphp

<x-layouts.app title="Calendar">

    <x-page-header
        :title="$from->format('F Y')"
        description="What is arranged, what is due, and what starts or finishes — drawn from the meetings, tasks and projects you can already see.">
        <x-slot:actions>
            <a href="{{ route('calendar.index', ['month' => $previous->format('Y-m')]) }}" class="btn btn-sm btn-secondary">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> {{ $previous->format('M') }}
            </a>
            <a href="{{ route('calendar.index') }}" class="btn btn-sm btn-ghost">Today</a>
            <a href="{{ route('calendar.index', ['month' => $next->format('Y-m')]) }}" class="btn btn-sm btn-secondary">
                {{ $next->format('M') }} <x-icon name="chevron-right" class="h-3.5 w-3.5"/>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- legend --}}
    <div class="mb-5 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-[var(--text-muted)]">
        @foreach ($sourceLabels as $key => [$type, $label])
            <span class="inline-flex items-center gap-1.5 {{ $sources[$key] ? '' : 'line-through opacity-60' }}">
                <span class="h-2 w-2 rounded-full {{ $dot[$type] }}"></span>
                {{ $label }}
                @unless ($sources[$key])
                    <span class="not-italic">— not visible to you</span>
                @endunless
            </span>
        @endforeach
    </div>

    @if (! $sources['meetings'] && ! $sources['tasks'] && ! $sources['projects'])
        <div class="card">
            <x-empty-state
                icon="calendar"
                title="Nothing to put on a calendar"
                description="A calendar shows meetings, task due dates and project milestones. You do not currently hold sight of any of the three, so there is nothing this page could honestly draw."/>
        </div>
    @else
        <div class="grid gap-6 lg:grid-cols-3">

            {{-- -------------------------------------------------------- the month --}}
            <div class="card overflow-hidden lg:col-span-2">
                <div class="grid grid-cols-7 border-b border-[var(--surface-border)] bg-[var(--surface-muted)]">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                        <div class="px-2 py-2 text-center text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                            {{ $weekday }}
                        </div>
                    @endforeach
                </div>

                @foreach ($weeks as $week)
                    <div class="grid grid-cols-7 border-b border-[var(--surface-border)] last:border-b-0">
                        @foreach ($week as $day)
                            @php
                                $key = $day->toDateString();
                                $dayEntries = $days[$key] ?? collect();
                                $outside = $day->month !== $from->month;
                                $isToday = $day->isSameDay($today);
                            @endphp

                            <div class="min-h-24 border-r border-[var(--surface-border)] p-1.5 last:border-r-0 {{ $outside ? 'bg-[var(--surface-muted)]/50' : '' }}">
                                <div class="mb-1 flex justify-end">
                                    <span class="flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-xs
                                        {{ $isToday ? 'bg-brand-600 font-medium text-white' : ($outside ? 'text-[var(--text-muted)] opacity-60' : 'text-[var(--text-muted)]') }}">
                                        {{ $day->day }}
                                    </span>
                                </div>

                                <div class="space-y-1">
                                    @foreach ($dayEntries->take(3) as $entry)
                                        <a href="{{ $entry['url'] }}"
                                           title="{{ $entry['reference'] }} — {{ $entry['title'] }}"
                                           class="flex items-center gap-1 rounded px-1 py-0.5 text-[11px] leading-tight hover:bg-[var(--surface-muted)] {{ $entry['muted'] ? 'line-through opacity-60' : '' }}">
                                            <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot[$entry['type']] ?? 'bg-slate-400' }}"></span>
                                            @if ($entry['time'])
                                                <span class="shrink-0 font-mono text-[var(--text-muted)]">{{ $entry['time'] }}</span>
                                            @endif
                                            <span class="truncate text-[var(--text-body)]">{{ $entry['title'] }}</span>
                                        </a>
                                    @endforeach

                                    @if ($dayEntries->count() > 3)
                                        <p class="px-1 text-[11px] text-[var(--text-muted)]">
                                            +{{ $dayEntries->count() - 3 }} more
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>

            {{-- -------------------------------------------------------- agenda --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Agenda</h3>
                    <span class="text-xs text-[var(--text-muted)]">{{ $entries->count() }} entries</span>
                </div>

                @if ($entries->isEmpty())
                    <x-empty-state
                        icon="calendar"
                        title="Nothing this month"
                        description="Either nothing is arranged or due in {{ $from->format('F') }}, or what there is sits outside your access."/>
                @else
                    <div class="max-h-[36rem] divide-y divide-[var(--surface-border)] overflow-y-auto">
                        @foreach ($days as $date => $dayEntries)
                            @php $day = \Illuminate\Support\Carbon::parse($date); @endphp

                            <div class="px-4 py-3">
                                <p class="mb-2 text-xs font-medium uppercase tracking-wide {{ $day->isSameDay($today) ? 'text-brand-600' : 'text-[var(--text-muted)]' }}">
                                    {{ $day->format('D j M') }}
                                    @if ($day->isSameDay($today))
                                        · today
                                    @endif
                                </p>

                                <ul class="space-y-2">
                                    @foreach ($dayEntries as $entry)
                                        <li class="flex gap-2">
                                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $dot[$entry['type']] ?? 'bg-slate-400' }}"></span>
                                            <div class="min-w-0">
                                                <a href="{{ $entry['url'] }}"
                                                   class="block truncate text-sm text-[var(--text-strong)] hover:text-brand-600 {{ $entry['muted'] ? 'line-through opacity-60' : '' }}">
                                                    {{ $entry['title'] }}
                                                </a>
                                                <p class="truncate text-xs text-[var(--text-muted)]">
                                                    @if ($entry['time'])
                                                        {{ $entry['time'] }} ·
                                                    @endif
                                                    <span class="font-mono">{{ $entry['reference'] }}</span>
                                                    @if ($entry['context'])
                                                        · {{ $entry['context'] }}
                                                    @endif
                                                </p>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <p class="mt-4 text-xs text-[var(--text-muted)]">
            Task entries are open tasks only — a task that has been closed stops occupying a day it can
            no longer be done on. Projects appear as two milestones rather than a bar across the month,
            because a calendar answers "what happens today"; duration belongs on the programme.
        </p>
    @endif

</x-layouts.app>
