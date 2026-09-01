{{--
    Permits to work.

    IT OPENS ON "LIVE NOW" AND NOTHING ELSE WOULD DO. Every other register in this application can
    reasonably open on a backlog; this one answers "what work is authorised on site at this moment",
    and that is the screen a control room leaves on a wall. `Permit::scopeLive()` is the definition —
    issued, accepted, not suspended, not closed, inside its window — and the view name is the only
    place a reader should have to look to know what they are seeing.

    THE WINDOW IS THE SECOND COLUMN, not a detail buried in the row. A permit that expired forty
    minutes ago while a crew is still working is the failure this register exists to catch, so the
    expiry is coloured against the clock rather than left as plain text.

    Expects: $permits, $statuses, $projects, $permitTypes, $views, $filters, $canCreate
--}}
<x-layouts.app title="Permits to work">

    <x-page-header
        title="Permits to work"
        description="What has been authorised, who accepted it, and how long it lasts.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('permits.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Request a permit
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="permits.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="permits.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title, location or equipment">
        <x-slot:extra>
            <div>
                <label for="type" class="label">Permit type</label>
                <x-select name="type" :options="$permitTypes"
                          :selected="$filters['type']" placeholder="Any type"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$permits" icon="lock"
                      title="No permits"
                      description="Either nothing matches these filters, or the permits that do sit on projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('permits.create') }}" class="btn btn-primary btn-sm">Request a permit</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Work</th>
                <th>Type</th>
                <th>Valid until</th>
                <th>Accepted by</th>
                <th>State</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($permits as $permit)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('permits.show', $permit) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $permit->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $permit->requester?->name ?: 'Unattributed' }}
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('permits.show', $permit) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $permit->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($permit->location) {{ $permit->location }} @endif
                            @if ($permit->project) · {{ $permit->project->displayName() }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $permit->permitType?->name ?: '—' }}
                    </td>

                    {{-- Coloured against the clock, not against the status column: a permit can be
                         perfectly well "issued" and forty minutes out of date at the same time. --}}
                    <td class="whitespace-nowrap">
                        @if ($permit->valid_to === null)
                            <span class="text-[var(--text-muted)]">No end set</span>
                        @else
                            <span @class([
                                'text-sm',
                                'font-medium text-rose-600' => $permit->hasExpired() && ! $permit->isClosedOut(),
                                'text-[var(--text-muted)]' => ! ($permit->hasExpired() && ! $permit->isClosedOut()),
                            ])>
                                {{ $permit->valid_to->format('j M Y H:i') }}
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $permit->acceptor?->name ?: '—' }}
                    </td>

                    {{-- The lifecycle in one word, read off the record's own columns in the order
                         they override each other: cancelled beats closed beats suspended. --}}
                    <td class="whitespace-nowrap">
                        @if ($permit->cancelled_at)
                            <x-badge tone="neutral">Cancelled</x-badge>
                        @elseif ($permit->closed_at)
                            <x-badge tone="success">Closed</x-badge>
                        @elseif ($permit->isSuspended())
                            <x-badge tone="danger">Suspended</x-badge>
                        @elseif ($permit->isLive())
                            <x-badge tone="success">Live</x-badge>
                        @elseif ($permit->hasExpired())
                            <x-badge tone="danger">Expired</x-badge>
                        @elseif ($permit->isIssued())
                            <x-badge tone="warning">Issued, not accepted</x-badge>
                        @else
                            <x-badge tone="neutral">Awaiting issue</x-badge>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($permit->status)
                            <x-badge :tone="$permit->status->tone()">{{ $permit->status->label }}</x-badge>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
