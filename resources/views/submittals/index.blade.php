{{--
    Submittals — packages sent out for somebody else's review, and what came back.

    IT OPENS ON "AWAITING REVIEW" AND THE SECOND TAB IS "LATE", because the only reason this
    register is kept is to be able to say, with dates, how long a package has been sitting on
    somebody's desk. A submittal register that opened on everything ever submitted would answer
    a question nobody asks.

    THE ATTEMPT NUMBER IS ON THE NUMBER, not in a column of its own. "Attempt 3" beside a
    reference is the single most useful fact in a schedule argument, and it costs a line of
    subtext rather than a column that is blank for nine rows in ten.

    Expects: $submittals, $statuses, $projects, $types, $views, $filters, $canCreate
--}}
<x-layouts.app title="Submittals">

    <x-page-header
        title="Submittals"
        description="Everything sent out for approval, who is holding it, and how long they have held it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('submittals.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New package
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="submittals.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="submittals.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number or title"/>

    <x-register.table :records="$submittals" icon="archive"
                      title="No packages"
                      description="Either nothing matches these filters, or the packages that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('submittals.create') }}" class="btn btn-primary btn-sm">New package</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Title</th>
                <th>With</th>
                <th>Items</th>
                <th>Review due</th>
                <th>Code</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($submittals as $submittal)
                @php
                    $left = $submittal->daysRemaining();
                    $attempt = $submittal->resubmittal_of_id !== null ? $submittal->attemptNumber() : 1;
                @endphp
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('submittals.show', $submittal) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $submittal->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($attempt > 1)
                                Attempt {{ $attempt }}
                            @elseif ($submittal->revision)
                                Rev {{ $submittal->revision }}
                            @else
                                First attempt
                            @endif
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('submittals.show', $submittal) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $submittal->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $types[$submittal->type] ?? $submittal->type ?? 'Unclassified' }}
                            @if ($submittal->project) · {{ $submittal->project->displayName() }} @endif
                        </span>
                    </td>

                    <td class="text-[var(--text-muted)]">{{ $submittal->party_name ?? '—' }}</td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $submittal->items_count }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($submittal->required_by)
                            <span class="text-[var(--text-body)]">{{ $submittal->required_by->format('j M Y') }}</span>
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

                    <td class="whitespace-nowrap">
                        @if ($submittal->review_code)
                            <span class="font-mono text-xs text-[var(--text-strong)]">{{ $submittal->review_code }}</span>
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $submittal->reviewed_at?->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($submittal->isOverdue())
                            <x-badge tone="danger">Late</x-badge>
                        @elseif ($submittal->status)
                            <x-badge :tone="$submittal->status->tone()">{{ $submittal->status->label }}</x-badge>
                        @elseif ($submittal->isReviewed())
                            <x-badge tone="success">Reviewed</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
