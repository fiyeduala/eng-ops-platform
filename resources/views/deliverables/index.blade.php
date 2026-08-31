{{--
    The deliverables register — what this company has promised to produce, and when.

    IT OPENS ON "OUTSTANDING", never on everything. A deliverables register exists to answer one
    question repeatedly — what is still owed and what is late — and a list that leads with two
    thousand issued lines from last year answers it badly.

    THE DATE SHOWN IS THE EFFECTIVE ONE: actual if it happened, else forecast, else planned. The
    promised date sits underneath whenever the two disagree, because a register that quietly replaced
    the promise with the current hope would make slippage invisible, which is the single most
    expensive thing this screen could do.

    Expects: $deliverables, $statuses, $projects, $units, $types, $views, $filters, $canCreate
--}}
<x-layouts.app title="Deliverables">

    <x-page-header
        title="Deliverables"
        description="Every document, drawing, calculation and report this organization has undertaken to produce — with the date it was promised for and how far along it is.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('deliverables.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New deliverable
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="deliverables.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="deliverables.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number or title">
        @if (count($units))
            <x-slot:extra>
                <div>
                    <label for="discipline" class="label">Discipline</label>
                    <x-select name="discipline" :options="$units" :selected="$filters['discipline']"
                              placeholder="Any discipline"/>
                </div>
            </x-slot:extra>
        @endif
    </x-register.filters>

    <x-register.table :records="$deliverables" icon="flag"
                      title="Nothing on the register"
                      description="Either nothing matches, or the deliverables that do belong to projects and departments your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('deliverables.create') }}" class="btn btn-primary btn-sm">Add a deliverable</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Title</th>
                <th>Discipline</th>
                <th>Responsible</th>
                <th>Due</th>
                <th>Progress</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($deliverables as $deliverable)
                @php $slip = $deliverable->slippageDays(); @endphp
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('deliverables.show', $deliverable) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $deliverable->number }}
                        </a>
                    </td>

                    <td>
                        <a href="{{ route('deliverables.show', $deliverable) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $deliverable->title }}
                        </a>
                        @if ($deliverable->project)
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $deliverable->project->displayName() }}
                            </span>
                        @endif
                    </td>

                    <td class="text-[var(--text-muted)]">{{ $deliverable->discipline?->name ?? '—' }}</td>

                    <td class="text-[var(--text-muted)]">{{ $deliverable->responsible?->name ?? 'Unassigned' }}</td>

                    <td class="whitespace-nowrap">
                        @if ($deliverable->effectiveIssueDate())
                            <span class="text-[var(--text-body)]">
                                {{ $deliverable->effectiveIssueDate()->format('j M Y') }}
                            </span>

                            {{-- The promise, whenever it is no longer what we expect. --}}
                            @if ($slip !== null && $slip !== 0 && $deliverable->planned_issue_on)
                                <span @class([
                                    'block text-xs',
                                    'text-rose-600' => $slip > 0,
                                    'text-emerald-600' => $slip < 0,
                                ])>
                                    {{ $slip > 0 ? $slip.' day'.($slip === 1 ? '' : 's').' late' : abs($slip).' early' }}
                                    · promised {{ $deliverable->planned_issue_on->format('j M') }}
                                </span>
                            @endif
                        @else
                            <span class="text-[var(--text-muted)]">No date</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        <div class="flex items-center gap-2">
                            <div class="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                                <div class="h-full rounded-full bg-brand-500"
                                     style="width: {{ (int) $deliverable->progress_percent }}%"></div>
                            </div>
                            <span class="text-xs text-[var(--text-muted)]">{{ (int) $deliverable->progress_percent }}%</span>
                        </div>
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($deliverable->isIssued())
                            <x-badge tone="success">Issued</x-badge>
                        @elseif ($deliverable->isOverdue())
                            <x-badge tone="danger">Late</x-badge>
                        @elseif ($deliverable->status)
                            <x-badge :tone="$deliverable->status->tone()">{{ $deliverable->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
