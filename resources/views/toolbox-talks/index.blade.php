{{--
    Toolbox talks.

    IT OPENS ON "PLANNED" — the talks nobody has recorded yet — because a briefing that was given and
    never written down and a briefing that was never given look identical from here, and only one of
    them can still be fixed this morning.

    THE ATTENDEE COUNT IS THE COLUMN THAT MATTERS. A talk with a title, a topic and nought attendees
    is not a record of anything; it is a plan. The count is what an inspector counts.

    Expects: $talks, $statuses, $projects, $views, $filters, $canCreate
--}}
<x-layouts.app title="Toolbox talks">

    <x-page-header
        title="Toolbox talks"
        description="The five-minute briefing, and the list of who was there.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('toolbox-talks.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Plan a talk
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="toolbox-talks.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="toolbox-talks.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or topic"/>

    <x-register.table :records="$talks" icon="users"
                      title="No toolbox talks"
                      description="Either nothing matches these filters, or the talks that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('toolbox-talks.create') }}" class="btn btn-primary btn-sm">Plan a talk</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Topic</th>
                <th>Presented by</th>
                <th>Held</th>
                <th class="text-right">Attended</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($talks as $talk)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('toolbox-talks.show', $talk) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $talk->number }}
                        </a>
                        @if ($talk->duration_minutes)
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $talk->duration_minutes }} min
                            </span>
                        @endif
                    </td>

                    <td>
                        <a href="{{ route('toolbox-talks.show', $talk) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $talk->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($talk->topic) {{ $talk->topic }} @endif
                            @if ($talk->location) · {{ $talk->location }} @endif
                            @if ($talk->project) · {{ $talk->project->displayName() }} @endif
                        </span>
                    </td>

                    {{-- `presenterLabel()` because the person who gave the talk is very often a
                         subcontractor's supervisor with no account here. --}}
                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $talk->presenterLabel() }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($talk->held_at)
                            <span class="text-sm text-[var(--text-muted)]">
                                {{ $talk->held_at->format('j M Y H:i') }}
                            </span>
                        @else
                            <span class="text-sm text-amber-600">Not recorded</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-right tabular-nums">
                        @if ($talk->wasHeld() && $talk->attendees_count === 0)
                            <span class="font-medium text-rose-600">0</span>
                        @else
                            {{ $talk->attendees_count }}
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($talk->status)
                            <x-badge :tone="$talk->status->tone()">{{ $talk->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Planned</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
