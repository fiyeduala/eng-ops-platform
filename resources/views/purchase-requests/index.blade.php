{{--
    The requisition register — the first link in the chain (§90).

    IT SORTS BY WHEN THE THING IS NEEDED, soonest first, with undated requests last. A purchase
    request is a deadline wearing a form: the useful question is never "what was raised most
    recently" but "what has to be ordered this week to arrive in time".

    THERE IS NO VALUE COLUMN, and that is deliberate rather than an omission. A requisition carries
    an ESTIMATE — what the engineer thinks it costs — and showing estimates in a register that
    people read as a spend report is how estimates become budgets. The estimated total is on the
    request's own page, labelled as an estimate. What was actually committed lives on the order.

    "APPROVED, NOT YET ORDERED" IS THE VIEW THAT EARNS ITS PLACE. It is the queue nobody owns: the
    engineer thinks buying is handling it, buying has not seen it, and the request sits approved for
    three weeks. Naming the queue is most of fixing it.

    Expects: $purchaseRequests, $statuses, $projects, $priorities, $views, $filters, $canCreate
--}}
<x-layouts.app title="Purchase requests">

    <x-page-header
        title="Purchase requests"
        description="What the work needs, why, and by when — before anybody commits money to it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('purchase-requests.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise a request
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="purchase-requests.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="purchase-requests.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or justification">
        <x-slot:extra>
            <div>
                <label for="priority" class="label">Priority</label>
                <x-select name="priority" :options="$priorities" :selected="$filters['priority']"
                          placeholder="Any priority"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$purchaseRequests" icon="cart"
                      title="No purchase requests"
                      description="Nothing has been requested that your access reaches. Requests are internal by default and follow the unit that raised them.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('purchase-requests.create') }}" class="btn btn-primary btn-sm">Raise a request</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Request</th>
                <th>Project</th>
                <th class="text-right">Lines</th>
                <th>Priority</th>
                <th>Needed by</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($purchaseRequests as $purchaseRequest)
                <tr>
                    <td>
                        <a href="{{ route('purchase-requests.show', $purchaseRequest) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $purchaseRequest->title }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $purchaseRequest->number }}
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $purchaseRequest->project?->name ?: '—' }}
                    </td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                        {{ $purchaseRequest->items_count }}
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($purchaseRequest->priority)
                            {{-- The list is the company's own, so the styling keys off the position
                                 in that list rather than off a word this code claims to recognise.
                                 A company whose top priority is called "AOG" gets the same red. --}}
                            <span @class([
                                'font-medium text-rose-600' => $purchaseRequest->priority === array_key_last($priorities),
                                'text-[var(--text-body)]' => $purchaseRequest->priority !== array_key_last($priorities),
                            ])>
                                {{ $priorities[$purchaseRequest->priority] ?? $purchaseRequest->priority }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($purchaseRequest->required_by)
                            <span @class([
                                'font-medium text-rose-600' => $purchaseRequest->required_by->isPast()
                                    && ! $purchaseRequest->status?->is_terminal,
                                'text-[var(--text-muted)]' => ! $purchaseRequest->required_by->isPast()
                                    || $purchaseRequest->status?->is_terminal,
                            ])>
                                {{ $purchaseRequest->required_by->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Not stated</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($purchaseRequest->status)
                            <x-badge :tone="$purchaseRequest->status->tone()">{{ $purchaseRequest->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
