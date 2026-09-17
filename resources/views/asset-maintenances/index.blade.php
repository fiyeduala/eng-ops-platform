{{--
    Job cards, in the order a maintenance meeting runs.

    THIS REGISTER OPENS ON `overdue`, which is the only one in the platform that does. Every other
    register opens on the work outstanding; here the work outstanding has a DATE on it, and a service
    that slipped three weeks ago outranks one due next month. The tabs read overdue, due soon, in
    progress, breakdowns, everything — which is the order somebody actually asks about them.

    THE DUE COLUMN IS SORTED FIRST AND NULLS LAST. A job with no due date is a breakdown being worked
    on now; a job with a due date is a promise. Interleaving them by id would bury the promises.

    Expects: $maintenances, $statuses, $projects, $assets, $views, $filters, $canCreate
--}}
<x-layouts.app title="Maintenance">

    <x-page-header
        title="Maintenance"
        description="What is due, what is being worked on, and what it cost.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('asset-maintenances.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise a job card
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="asset-maintenances.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="asset-maintenances.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Reference, title or work order">
        <x-slot:extra>
            <div>
                <label for="asset" class="label">Asset</label>
                <x-select name="asset" :options="$assets" :selected="$filters['asset']"
                          placeholder="Any asset"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$maintenances" icon="clipboard"
                      title="No job cards"
                      description="Nothing your access reaches. A job card follows the asset it is against, so plant belonging to another site may be out of view.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('asset-maintenances.create') }}" class="btn btn-primary btn-sm">Raise a job card</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Job</th>
                <th>Asset</th>
                <th>Kind</th>
                <th>Due</th>
                <th>With</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($maintenances as $job)
                <tr>
                    <td>
                        <a href="{{ route('asset-maintenances.show', $job) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $job->title }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $job->reference }}
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $job->asset?->code ?: '—' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        @if ($job->is_planned)
                            Planned
                        @else
                            {{-- Worth a chip rather than a word. A breakdown is not the same kind of
                                 row as a service and reads differently down a long list. --}}
                            <x-badge tone="warning">Breakdown</x-badge>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($job->due_on)
                            <span @class(['font-medium text-rose-600' => $job->isOverdue()])>
                                {{ $job->due_on->format('j M Y') }}
                            </span>
                        @elseif ($job->due_odometer)
                            <span class="font-mono text-xs text-[var(--text-muted)]">
                                at {{ number_format((float) $job->due_odometer) }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $job->performer?->name ?: $job->vendor?->name ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($job->status)
                            <x-badge :tone="$job->status->tone()">{{ $job->status->label }}</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
