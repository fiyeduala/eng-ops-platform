{{--
    RFIs, technical queries and site queries — one register of dated questions.

    IT OPENS ON "WE OWE AN ANSWER". That is the whole argument for keeping direction as a first-class
    field: mixed together, a company chases the client for forty answers while quietly sitting on
    thirty of its own, and the register that was supposed to prevent that becomes the thing hiding
    it. The two halves are separate views and the one we are responsible for comes first.

    THE QUESTION TEXT IS NOT SHOWN IN THE LIST, and search does not match on it. The body of an RFI
    names what a design got wrong; matching on it would turn the search box into an oracle that
    reports, through the result count alone, what is inside records the reader may list but not open
    (§61).

    Expects: $rfis, $statuses, $projects, $kinds, $views, $filters, $canCreate
--}}
<x-layouts.app title="RFIs & queries">

    <x-page-header
        title="RFIs & queries"
        description="Every dated question asked or answered on this organization's projects, and the clock running on each one.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('rfis.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise a query
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="rfis.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="rfis.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number or subject">
        <x-slot:extra>
            <div>
                <label for="kind" class="label">Kind</label>
                <x-select name="kind" :options="$kinds" :selected="$filters['kind']" placeholder="Any kind"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$rfis" icon="info"
                      title="No questions"
                      description="Either nothing matches, or the queries that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('rfis.create') }}" class="btn btn-primary btn-sm">Raise a query</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Subject</th>
                <th>Direction</th>
                <th>Party</th>
                <th>Due</th>
                <th>Impact</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($rfis as $rfi)
                @php $left = $rfi->daysRemaining(); @endphp
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('rfis.show', $rfi) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $rfi->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">{{ $rfi->kind?->label() }}</span>
                    </td>

                    <td>
                        <a href="{{ route('rfis.show', $rfi) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $rfi->subject }}
                        </a>
                        @if ($rfi->project)
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $rfi->project->displayName() }}
                                @if ($rfi->discipline) · {{ $rfi->discipline->name }} @endif
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($rfi->answerIsOwedByUs())
                            <x-badge tone="warning">We answer</x-badge>
                        @else
                            <x-badge tone="neutral">They answer</x-badge>
                        @endif
                    </td>

                    <td class="text-[var(--text-muted)]">{{ $rfi->party_name ?? '—' }}</td>

                    <td class="whitespace-nowrap">
                        @if ($rfi->required_by)
                            <span class="text-[var(--text-body)]">{{ $rfi->required_by->format('j M Y') }}</span>
                            @if ($left !== null)
                                <span @class([
                                    'block text-xs',
                                    'text-rose-600' => $left < 0,
                                    'text-amber-600' => $left >= 0 && $left <= 2,
                                    'text-[var(--text-muted)]' => $left > 2,
                                ])>
                                    {{ $left < 0
                                        ? abs($left).' day'.(abs($left) === 1 ? '' : 's').' late'
                                        : ($left === 0 ? 'due today' : $left.' day'.($left === 1 ? '' : 's').' left') }}
                                </span>
                            @endif
                        @else
                            <span class="text-[var(--text-muted)]">No date</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-xs text-[var(--text-muted)]">
                        @if ($rfi->cost_impact || $rfi->schedule_impact)
                            @if ($rfi->cost_impact)
                                <x-badge tone="danger">Cost</x-badge>
                            @endif
                            @if ($rfi->schedule_impact)
                                <x-badge tone="warning">Time</x-badge>
                            @endif
                        @else
                            —
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($rfi->isAnswered())
                            <x-badge tone="success">Answered</x-badge>
                        @elseif ($rfi->isOverdue())
                            <x-badge tone="danger">Overdue</x-badge>
                        @elseif ($rfi->status)
                            <x-badge :tone="$rfi->status->tone()">{{ $rfi->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
