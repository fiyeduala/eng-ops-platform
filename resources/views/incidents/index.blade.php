{{--
    The incident register.

    IT OPENS ON "OPEN", NOT ON "EVERYTHING". A safety adviser's question is never "how many
    incidents have we ever had"; it is "what is still unanswered this morning". Everything else on
    this screen is a tab away.

    THE REPORTING LAG IS A COLUMN. Occurred and reported are shown together because the gap between
    them is a number regulators ask for by name, and a register that hides it inside a record is a
    register nobody audits.

    NO MEDICAL DETAIL APPEARS HERE AT ALL. Not injury, not body part, not days lost. Those live on
    the person rows behind `hse_incident.view_confidential`, and a list view that leaked them one
    column at a time would be the easiest §68 breach in the application to write by accident.

    Expects: $incidents, $statuses, $projects, $categories, $severities, $views, $filters, $canCreate
--}}
<x-layouts.app title="Incidents">

    <x-page-header
        title="Incidents"
        description="What happened, when it was reported, and what has been done about it since.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('incidents.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Report an incident
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="incidents.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="incidents.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or location">
        <x-slot:extra>
            <div>
                <label for="category" class="label">Category</label>
                <x-select name="category" :options="$categories" :selected="$filters['category']"
                          placeholder="Any category"/>
            </div>
            <div>
                <label for="severity" class="label">Severity</label>
                <x-select name="severity" :options="$severities" :selected="$filters['severity']"
                          placeholder="Any severity"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$incidents" icon="alert"
                      title="No incidents"
                      description="Either nothing matches these filters, or the incidents that do sit on projects and departments your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('incidents.create') }}" class="btn btn-primary btn-sm">Report an incident</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>What happened</th>
                <th>Category</th>
                <th>Severity</th>
                <th>Occurred / reported</th>
                <th class="text-right">People</th>
                <th class="text-right">Actions</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($incidents as $incident)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('incidents.show', $incident) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $incident->number }}
                        </a>
                        @if ($incident->is_reportable)
                            <span class="mt-1 block">
                                <x-badge tone="danger">Reportable</x-badge>
                            </span>
                        @endif
                    </td>

                    <td>
                        <a href="{{ route('incidents.show', $incident) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $incident->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $incident->project?->displayName() ?? 'No project' }}
                            @if ($incident->location) · {{ $incident->location }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">{{ $incident->category ?? '—' }}</td>

                    <td class="whitespace-nowrap">
                        @if ($incident->severity)
                            <span class="text-[var(--text-body)]">{{ $incident->severity }}</span>
                            @if ($incident->potential_severity && $incident->potential_severity !== $incident->severity)
                                <span class="block text-xs text-[var(--text-muted)]">
                                    could have been {{ $incident->potential_severity }}
                                </span>
                            @endif
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    {{-- The lag, not just the date. An incident reported four days late is a
                         different fact from one reported the same hour. --}}
                    <td class="whitespace-nowrap text-sm">
                        <span class="text-[var(--text-body)]">
                            {{ $incident->occurred_at?->format('j M Y H:i') ?? 'Undated' }}
                        </span>
                        @if ($incident->occurred_at && $incident->reported_at)
                            @php $lagHours = $incident->occurred_at->diffInHours($incident->reported_at); @endphp
                            <span @class([
                                'block text-xs',
                                'text-amber-600' => $lagHours >= 24,
                                'text-[var(--text-muted)]' => $lagHours < 24,
                            ])>
                                reported {{ $lagHours < 24 ? $lagHours.'h' : round($lagHours / 24).'d' }} later
                            </span>
                        @endif
                    </td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                        {{ $incident->people_count }}
                    </td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                        {{ $incident->corrective_actions_count }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($incident->status)
                            <x-badge :tone="$incident->status->tone()">{{ $incident->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Reported</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
