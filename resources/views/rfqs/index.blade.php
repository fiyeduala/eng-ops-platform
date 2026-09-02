{{--
    The enquiry register — requests for quotation.

    THE COLUMN THAT MATTERS IS "RESPONSES". An enquiry with six invitations and one response two
    days before it closes is the thing a buyer needs to see on a Monday morning; an enquiry with six
    of six is finished with. That ratio is why invitations and quotations are counted in the query
    rather than looked up per row.

    "CLOSING THIS WEEK" IS A DATE QUERY. `closes_at` passes whether or not anybody clicks anything,
    exactly as a contract passes its end date — so the deadline is computed, and `closed` remains a
    thing somebody does rather than a thing that happens to them.

    Expects: $rfqs, $statuses, $projects, $views, $filters, $canCreate
--}}
<x-layouts.app title="Enquiries">

    <x-page-header
        title="Enquiries"
        description="What was asked of the market, who was asked, and what came back.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('rfqs.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise an enquiry
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="rfqs.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="rfqs.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or scope"/>

    <x-register.table :records="$rfqs" icon="mail"
                      title="No enquiries"
                      description="Nothing has been sent out to the market that your access reaches.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('rfqs.create') }}" class="btn btn-primary btn-sm">Raise an enquiry</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Enquiry</th>
                <th>Project</th>
                <th class="text-right">Lines</th>
                <th class="text-right">Responses</th>
                <th>Closes</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($rfqs as $rfq)
                <tr>
                    <td>
                        <a href="{{ route('rfqs.show', $rfq) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $rfq->title }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $rfq->number }}</span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">{{ $rfq->project?->name ?: '—' }}</td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">{{ $rfq->items_count }}</td>

                    {{-- Quotations received against suppliers invited. Nought of five with a day to
                         go is the number somebody has to act on. --}}
                    <td class="whitespace-nowrap text-right font-mono text-sm">
                        <span @class([
                            'text-rose-600' => $rfq->quotations_count === 0 && $rfq->invitations_count > 0,
                            'text-[var(--text-body)]' => $rfq->quotations_count > 0,
                            'text-[var(--text-muted)]' => $rfq->invitations_count === 0,
                        ])>{{ $rfq->quotations_count }}</span>
                        <span class="text-[var(--text-muted)]">/ {{ $rfq->invitations_count }}</span>
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($rfq->closes_at)
                            <span @class([
                                'font-medium text-rose-600' => $rfq->closes_at->isPast()
                                    && ! $rfq->status?->is_terminal,
                                'text-[var(--text-muted)]' => ! $rfq->closes_at->isPast()
                                    || $rfq->status?->is_terminal,
                            ])>{{ $rfq->closes_at->format('j M Y, H:i') }}</span>
                        @else
                            <span class="text-[var(--text-muted)]">No deadline</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($rfq->status)
                            <x-badge :tone="$rfq->status->tone()">{{ $rfq->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
