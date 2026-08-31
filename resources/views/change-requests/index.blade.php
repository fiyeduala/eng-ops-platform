{{--
    Change requests — what changed, why, and what it cost.

    IT OPENS ON "AWAITING A DECISION" because an undecided change is the only kind that can still
    be argued about cheaply. Everything else on this register is history.

    THE MONEY AND THE DAYS ARE COLUMNS, NOT DETAIL. A change register that makes you open each row
    to find the number is a register nobody totals, and the total is the entire reason a commercial
    manager opens this page.

    DISPOSITION IS SHOWN ALONGSIDE STATUS, NEVER INSTEAD OF IT. "Closed" and "rejected" are
    different facts — a request can be closed as approved, closed as rejected, or approved and
    still open while the variation order is drafted — and collapsing them into one column is how a
    register starts disagreeing with the contract file.

    Expects: $changeRequests, $statuses, $projects, $origins, $views, $filters, $canCreate
--}}
<x-layouts.app title="Change requests">

    <x-page-header
        title="Change requests"
        description="Every change to scope, its origin, what it costs and who said yes.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('change-requests.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise a change
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="change-requests.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="change-requests.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number or title">
        <x-slot:extra>
            <div>
                <label for="origin" class="label">Origin</label>
                <x-select name="origin" :options="$origins" :selected="$filters['origin']"
                          placeholder="Any origin"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$changeRequests" icon="activity"
                      title="No change requests"
                      description="Either nothing matches these filters, or the changes that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('change-requests.create') }}" class="btn btn-primary btn-sm">Raise a change</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Title</th>
                <th>Origin</th>
                <th class="text-right">Cost</th>
                <th class="text-right">Days</th>
                <th>Decision</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($changeRequests as $change)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('change-requests.show', $change) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $change->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $change->raised_on?->format('j M Y') ?? 'Undated' }}
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('change-requests.show', $change) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $change->title }}
                        </a>
                        @if ($change->project)
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $change->project->displayName() }}
                                @if ($change->discipline) · {{ $change->discipline->name }} @endif
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $change->originLabel() ?? '—' }}
                    </td>

                    {{-- No currency symbol. The company's books say what the number is in; printing
                         a symbol this platform guessed would be worse than printing none (§47). --}}
                    <td class="whitespace-nowrap text-right font-mono text-sm">
                        @if ($change->cost_impact === null)
                            <span class="text-[var(--text-muted)]">—</span>
                        @else
                            <span @class([
                                'text-rose-600' => (float) $change->cost_impact > 0,
                                'text-emerald-600' => (float) $change->cost_impact < 0,
                                'text-[var(--text-muted)]' => (float) $change->cost_impact === 0.0,
                            ])>
                                {{ number_format((float) $change->cost_impact, 2) }}
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-right font-mono text-sm">
                        @if ($change->schedule_impact_days === null)
                            <span class="text-[var(--text-muted)]">—</span>
                        @else
                            <span @class([
                                'text-rose-600' => $change->schedule_impact_days > 0,
                                'text-emerald-600' => $change->schedule_impact_days < 0,
                                'text-[var(--text-muted)]' => $change->schedule_impact_days === 0,
                            ])>
                                {{ $change->schedule_impact_days > 0 ? '+' : '' }}{{ $change->schedule_impact_days }}
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($change->disposition)
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $change->disposition->badgeClasses() }}">
                                {{ $change->disposition->label() }}
                            </span>
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $change->decided_at?->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Undecided</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($change->status)
                            <x-badge :tone="$change->status->tone()">{{ $change->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
