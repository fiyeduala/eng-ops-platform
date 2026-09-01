{{--
    Observations — near misses, unsafe conditions, and the good ones too.

    POSITIVE OBSERVATIONS ARE IN THE SAME REGISTER, deliberately. A list that only ever records
    failures teaches a workforce that reporting is punishment, and a workforce that believes that
    stops reporting the near misses — which are the only free warnings a company ever gets.

    ANONYMOUS CARDS SHOW AS ANONYMOUS AND THAT IS THE WHOLE POINT. `observerLabel()` reads a null
    `observed_by` rather than hiding a stored name, because the column was never written. The
    "Raised by me" view therefore cannot include a person's own anonymous cards — an honest cost of a
    promise worth keeping, and the screen says so rather than leaving it to be discovered.

    Expects: $observations, $statuses, $projects, $types, $categories, $riskLevels, $views, $filters,
             $canCreate
--}}
<x-layouts.app title="Observations">

    <x-page-header
        title="Observations"
        description="What somebody noticed before it became an incident.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('hse-observations.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Report an observation
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="hse-observations.index" :views="$views" :current="$filters['view']"/>

    @if ($filters['view'] === 'mine')
        <p class="mb-4 text-sm text-[var(--text-muted)]">
            Anonymous cards are not listed here. Nothing was recorded linking them to you — that is
            what anonymous means.
        </p>
    @endif

    <x-register.filters route="hse-observations.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number or title">
        <x-slot:extra>
            <div>
                <label for="observation_type" class="label">Type</label>
                <x-select name="observation_type" :options="$types"
                          :selected="$filters['observation_type']" placeholder="Any type"/>
            </div>
            <div>
                <label for="category" class="label">Category</label>
                <x-select name="category" :options="$categories" :selected="$filters['category']"
                          placeholder="Any category"/>
            </div>
            <div>
                <label for="risk_level" class="label">Risk</label>
                <x-select name="risk_level" :options="$riskLevels" :selected="$filters['risk_level']"
                          placeholder="Any level"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$observations" icon="info"
                      title="No observations"
                      description="Either nothing matches these filters, or the cards that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('hse-observations.create') }}" class="btn btn-primary btn-sm">Report an observation</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>What was seen</th>
                <th>Type</th>
                <th>Risk</th>
                <th>Raised by</th>
                <th class="text-right">Actions</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($observations as $observation)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('hse-observations.show', $observation) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $observation->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $observation->observed_at?->format('j M Y') ?? 'Undated' }}
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('hse-observations.show', $observation) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $observation->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($observation->location) {{ $observation->location }} @endif
                            @if ($observation->project) · {{ $observation->project->displayName() }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $observation->observation_type ?: '—' }}
                        @if ($observation->category)
                            <span class="block text-xs">{{ $observation->category }}</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($observation->risk_level)
                            <x-badge :tone="in_array(strtolower($observation->risk_level), ['high', 'critical'], true) ? 'danger' : 'neutral'">
                                {{ $observation->risk_level }}
                            </x-badge>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $observation->observerLabel() }}
                    </td>

                    <td class="whitespace-nowrap text-right tabular-nums">
                        @if ($observation->corrective_actions_count > 0)
                            {{ $observation->corrective_actions_count }}
                        @elseif ($observation->action_required)
                            <span class="text-xs text-amber-600">needed</span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($observation->status)
                            <x-badge :tone="$observation->status->tone()">{{ $observation->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
