{{--
    Investigations — the register of unanswered "why".

    IT OPENS ON "OUTSTANDING" and it sorts by due date with the undated pushed to the bottom, because
    the only useful question this page answers is which investigation is late. A list ordered by
    number tells a safety manager nothing they could act on.

    THERE IS NO "NEW INVESTIGATION" BUTTON, and its absence is the design. An investigation exists
    about an incident or it does not exist at all, so the only way to open one is from the incident's
    own page. A create screen here would invite records with no incident behind them.

    THE INCIDENT IS A COLUMN. An investigation number means nothing on its own — the reader wants to
    know what happened, and the link back is how they find out.

    Expects: $investigations, $statuses, $projects, $methods, $views, $filters
--}}
<x-layouts.app title="Investigations">

    <x-page-header
        title="Investigations"
        description="What is still being looked into, who is looking into it, and what is late."/>

    <x-register.views route="investigations.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="investigations.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number or title">
        <x-slot:extra>
            <div>
                <label for="method" class="label">Method</label>
                <x-select name="method" :options="$methods" :selected="$filters['method']"
                          placeholder="Any method"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$investigations" icon="search"
                      title="No investigations"
                      description="Either nothing matches these filters, or the investigations that do belong to incidents your access does not reach.">
        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Into</th>
                <th>Led by</th>
                <th>Method</th>
                <th>Due</th>
                <th>Root cause</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($investigations as $investigation)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('investigations.show', $investigation) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $investigation->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            opened {{ $investigation->started_on?->format('j M Y') ?? '—' }}
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('investigations.show', $investigation) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $investigation->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($investigation->incident)
                                {{ $investigation->incident->number }}
                            @endif
                            @if ($investigation->project)
                                · {{ $investigation->project->displayName() }}
                            @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $investigation->lead?->name ?? 'Unassigned' }}
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $investigation->method ?: '—' }}
                    </td>

                    {{-- Late is a colour, not a badge. A register where half the rows shout stops
                         being read; the amber only appears once the date has actually passed and
                         the work is still open. --}}
                    <td class="whitespace-nowrap">
                        @if ($investigation->due_on)
                            @php $late = ! $investigation->isComplete()
                                && $investigation->due_on->isBefore(now()->startOfDay()); @endphp
                            <span @class([
                                'text-sm',
                                'font-medium text-rose-600' => $late,
                                'text-[var(--text-muted)]' => ! $late,
                            ])>
                                {{ $investigation->due_on->format('j M Y') }}
                            </span>
                            @if ($late)
                                <span class="block text-xs text-rose-600">overdue</span>
                            @endif
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    {{-- The one column that says whether the investigation is worth anything. An
                         immediate cause without a root cause is a description, not an answer. --}}
                    <td class="whitespace-nowrap">
                        @if ($investigation->hasRootCause())
                            <x-badge tone="success">Recorded</x-badge>
                        @else
                            <x-badge tone="warning">Not yet</x-badge>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($investigation->status)
                            <x-badge :tone="$investigation->status->tone()">{{ $investigation->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
