{{--
    The meeting register.

    A meeting has no draft curtain — a scheduled meeting is real the moment it is scheduled, and
    hiding it from the people expected to attend would defeat the point. The curtain in this module
    is on the MINUTES, one field of a record everybody concerned can already see, and it is applied
    on the meeting page rather than here.

    "Awaiting minutes" is the view that does real work: meetings that have happened and whose record
    has not been circulated. It is the difference between a company that minutes its meetings and one
    that believes it does.

    Expects: $meetings, $statuses, $projects, $views, $filters, $canCreate
--}}
<x-layouts.app title="Meetings">

    <x-page-header
        title="Meetings"
        description="What is arranged, who is expected, and whether the record of what was decided has been circulated.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('calendar.index') }}" class="btn btn-sm btn-ghost">
                    <x-icon name="calendar" class="h-3.5 w-3.5"/> Calendar
                </a>
                <a href="{{ route('meetings.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New meeting
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('meetings.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Title or meeting number"/>
                <p class="help">Titles and numbers only — minutes are never searched.</p>
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
            @if (array_filter($filters, fn ($v, $k) => $v && ! ($k === 'view' && $v === 'upcoming'), ARRAY_FILTER_USE_BOTH))
                <a href="{{ route('meetings.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($meetings->isEmpty())
            <x-empty-state
                icon="users"
                title="No meetings you can see"
                description="Either nothing matches this filter, or the meetings it would match sit outside your access. Being invited to one is itself what gives you sight of it."/>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Meeting</th>
                            <th>When</th>
                            <th>Where</th>
                            <th>Status</th>
                            <th>Attending</th>
                            <th>Record</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($meetings as $meeting)
                            <tr class="{{ $meeting->isCancelled() ? 'opacity-60' : '' }}">
                                <td>
                                    <a href="{{ route('meetings.show', $meeting) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $meeting->title }}</a>
                                    <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $meeting->number }}</span>
                                    <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                        {{ $meeting->organizer?->name ?? 'No organizer' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap">
                                    <span class="text-[var(--text-strong)]">{{ $meeting->starts_at?->format('D j M Y') }}</span>
                                    <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                        {{ $meeting->starts_at?->format('H:i') }}
                                        @if ($meeting->ends_at)
                                            – {{ $meeting->ends_at->format('H:i') }}
                                        @endif
                                    </span>
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    @if ($meeting->location)
                                        {{ $meeting->location }}
                                    @elseif ($meeting->meeting_url)
                                        <span class="inline-flex items-center gap-1"><x-icon name="video" class="h-3.5 w-3.5"/> Online</span>
                                    @else
                                        <span class="italic">Not set</span>
                                    @endif
                                    @if ($meeting->project)
                                        <span class="mt-0.5 block text-xs">{{ $meeting->project->name }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($meeting->status)
                                        <x-badge :tone="$meeting->status->color ?? 'neutral'">{{ $meeting->status->label }}</x-badge>
                                    @else
                                        <span class="text-[var(--text-muted)]">—</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap text-[var(--text-muted)]">
                                    @if ((int) $meeting->participants_count === 0)
                                        <span class="italic">Nobody invited</span>
                                    @elseif ($meeting->isPast() && (int) $meeting->attended_count > 0)
                                        {{ (int) $meeting->attended_count }} of {{ (int) $meeting->participants_count }} attended
                                    @else
                                        {{ (int) $meeting->participants_count }} invited
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($meeting->minutesCirculated())
                                        <x-badge tone="success">
                                            <x-icon name="check" class="h-3 w-3"/> Circulated
                                        </x-badge>
                                    @elseif ($meeting->isPast() && ! $meeting->isCancelled())
                                        <x-badge tone="warning">
                                            <x-icon name="clock" class="h-3 w-3"/> Awaiting
                                        </x-badge>
                                    @else
                                        <span class="text-xs text-[var(--text-muted)]">—</span>
                                    @endif
                                    @if ((int) $meeting->decisions_count > 0)
                                        <span class="mt-0.5 block text-xs text-[var(--text-muted)]">
                                            {{ (int) $meeting->decisions_count }} decision(s)
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('meetings.show', $meeting) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-[var(--text-muted)]">
                    Showing {{ $meetings->firstItem() }}–{{ $meetings->lastItem() }} of {{ $meetings->total() }} you can see.
                </p>
                @if ($meetings->hasPages())
                    {{ $meetings->links() }}
                @endif
            </div>
        @endif
    </div>

</x-layouts.app>
