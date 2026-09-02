{{--
    What the market answered — the quotations register.

    THIS REGISTER IS SHORTER FOR MOST PEOPLE THAN ANY OTHER. A quotation defaults to confidential,
    so Gate 5 removes rows before this page ever sees them. That is deliberate: a live tender is the
    one place where a colleague knowing the other bids invalidates the process rather than merely
    embarrassing somebody. Nobody is told how many rows they were not shown (§61).

    "VALIDITY RUNNING OUT" IS A DATE QUERY, not a status. There is no `expired` state in the
    quotation vocabulary, because a quotation goes out of date whether or not anybody clicks
    anything — so the tab asks the date and the row shows the date going red on its own.

    THE TOTAL IS SHOWN IN THE CURRENCY IT WAS QUOTED IN. Converting here would invite comparing two
    rows quoted in different currencies at rates fixed on different days; the comparison screen is
    where conversion happens, once, against the rate stored on each row.

    Expects: $quotations, $statuses, $projects, $vendors, $views, $filters, $canCreate
--}}
<x-layouts.app title="Quotations">

    <x-page-header
        title="Quotations"
        description="What suppliers came back with, what it costs and how long the price holds.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('quotations.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Record a quotation
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="quotations.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="quotations.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, supplier reference or enquiry">
        <x-slot:extra>
            @if (count($vendors))
                <div>
                    <label for="vendor" class="label">Supplier</label>
                    <x-select name="vendor" :options="$vendors" :selected="$filters['vendor'] ?? null"
                              placeholder="Any supplier"/>
                </div>
            @endif

            {{-- Arrived here from an enquiry: the id rides along so "back to this enquiry's
                 quotations" survives a search. It narrows a list already narrowed by access. --}}
            @if (filled($filters['rfq'] ?? null))
                <input type="hidden" name="rfq" value="{{ $filters['rfq'] }}">
            @endif
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$quotations" icon="coins"
                      title="No quotations"
                      description="Nothing has been received that your access reaches.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('quotations.create') }}" class="btn btn-primary btn-sm">Record a quotation</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Quotation</th>
                <th>Supplier</th>
                <th>Enquiry</th>
                <th class="text-right">Lines</th>
                <th class="text-right">Total</th>
                <th>Holds until</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($quotations as $quotation)
                <tr>
                    <td>
                        <a href="{{ route('quotations.show', $quotation) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $quotation->number }}
                        </a>
                        @if ($quotation->reference)
                            {{-- The supplier's own reference is what they will quote back at you on
                                 the telephone, so it sits under ours rather than being hidden. --}}
                            <span class="block text-xs text-[var(--text-muted)]">
                                Their ref {{ $quotation->reference }}
                            </span>
                        @endif
                    </td>

                    <td class="text-sm text-[var(--text-body)]">{{ $quotation->vendor?->name ?: '—' }}</td>

                    <td class="text-sm text-[var(--text-muted)]">
                        @if ($quotation->rfq)
                            <a href="{{ route('rfqs.show', $quotation->rfq) }}"
                               class="hover:text-brand-600">{{ $quotation->rfq->number }}</a>
                        @else
                            <span title="Received without an enquiry behind it">Unsolicited</span>
                        @endif
                    </td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">{{ $quotation->items_count }}</td>

                    <td class="whitespace-nowrap text-right font-mono text-sm text-[var(--text-strong)]">
                        {{ number_format((float) $quotation->total, 2) }}
                        <span class="text-xs text-[var(--text-muted)]">{{ $quotation->currency }}</span>
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($quotation->valid_until)
                            <span @class([
                                'font-medium text-rose-600' => $quotation->hasExpired()
                                    && ! $quotation->wasAwarded(),
                                'font-medium text-amber-600' => $quotation->expiresWithin(14),
                                'text-[var(--text-muted)]' => ! $quotation->hasExpired()
                                    && ! $quotation->expiresWithin(14),
                            ])>{{ $quotation->valid_until->format('j M Y') }}</span>
                        @else
                            <span class="text-[var(--text-muted)]">Not stated</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($quotation->status)
                            <x-badge :tone="$quotation->status->tone()">{{ $quotation->status->label }}</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
