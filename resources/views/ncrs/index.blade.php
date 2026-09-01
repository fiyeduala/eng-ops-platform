{{--
    Non-conformance reports.

    IT OPENS ON "OPEN", AND THE TWO VIEWS BESIDE IT ARE THE TWO PLACES AN NCR STALLS. "Awaiting a
    response" is the party who was issued the finding not answering; "awaiting a disposition" is our
    own engineering not deciding. Those are different people, different chase-ups and different
    conversations, and one merged "overdue" bucket would hide which of the two it was.

    SEVERITY IS THE FIRST FILTER BECAUSE IT IS WHAT ROUTING BRANCHES ON. A company that sends
    critical findings to the quality manager and minor ones to the site engineer configures exactly
    that in the workflow, and this register has to be able to show either half of it.

    THE PARTY IS A NAME AND NOT A FOREIGN KEY. This platform holds no vendor ledger, and half of one
    invented here would be worse than the subcontractor's name typed in.

    Expects: $ncrs, $statuses, $projects, $severities, $categories, $sources, $views, $filters,
             $canCreate
--}}
<x-layouts.app title="Non-conformances">

    <x-page-header
        title="Non-conformances"
        description="Work that does not meet the specification, and the clause that says so.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('ncrs.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise an NCR
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="ncrs.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="ncrs.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title, location or party">
        <x-slot:extra>
            <div>
                <label for="severity" class="label">Severity</label>
                <x-select name="severity" :options="$severities"
                          :selected="$filters['severity']" placeholder="Any severity"/>
            </div>
            <div>
                <label for="category" class="label">Category</label>
                <x-select name="category" :options="$categories"
                          :selected="$filters['category']" placeholder="Any category"/>
            </div>
            <div>
                <label for="source" class="label">Source</label>
                <x-select name="source" :options="$sources"
                          :selected="$filters['source']" placeholder="Any source"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$ncrs" icon="alert-triangle"
                      title="No non-conformances"
                      description="Either nothing matches these filters, or the NCRs that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('ncrs.create') }}" class="btn btn-primary btn-sm">Raise an NCR</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Finding</th>
                <th>Severity</th>
                <th>Against</th>
                <th>Required by</th>
                <th>Where it has got to</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($ncrs as $ncr)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('ncrs.show', $ncr) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $ncr->number }}
                        </a>
                        @if ($ncr->qualityInspection)
                            <span class="block text-xs text-[var(--text-muted)]">
                                from {{ $ncr->qualityInspection->number }}
                            </span>
                        @endif
                    </td>

                    <td>
                        <a href="{{ route('ncrs.show', $ncr) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $ncr->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($ncr->category) {{ $categories[$ncr->category] ?? Str::headline($ncr->category) }} @endif
                            @if ($ncr->location) · {{ $ncr->location }} @endif
                            @if ($ncr->project) · {{ $ncr->project->displayName() }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($ncr->severity)
                            <span @class([
                                'font-medium text-rose-600' => $ncr->severity === 'critical',
                                'font-medium text-amber-600' => $ncr->severity === 'major',
                                'text-[var(--text-muted)]' => ! in_array($ncr->severity, ['critical', 'major'], true),
                            ])>
                                {{ $severities[$ncr->severity] ?? Str::headline($ncr->severity) }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        {{ $ncr->party_name ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($ncr->required_by)
                            <span @class([
                                'font-medium text-rose-600' => ! $ncr->isClosedOut() && $ncr->required_by->isPast(),
                                'text-[var(--text-muted)]' => $ncr->isClosedOut() || ! $ncr->required_by->isPast(),
                            ])>
                                {{ $ncr->required_by->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    {{-- Five acts belong to five different people, and the only question this column
                         is ever asked is which of them is the next one waiting. Read backwards from
                         the furthest point reached, so the answer is always the row's own. --}}
                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        @if ($ncr->isClosedOut())
                            Closed
                        @elseif ($ncr->isVerified())
                            Verified — awaiting closure
                        @elseif ($ncr->isDispositioned())
                            {{ Str::headline((string) $ncr->disposition) }} — awaiting verification
                        @elseif ($ncr->hasResponse())
                            Answered — awaiting a disposition
                        @else
                            Awaiting a response
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($ncr->status)
                            <x-badge :tone="$ncr->status->tone()">{{ $ncr->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Raised</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
