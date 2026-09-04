{{--
    The store queue.

    A MATERIAL REQUEST IS NOT A PURCHASE REQUEST, and the two registers are kept apart because the
    question each answers is different. This one asks the store for something already on a shelf;
    the other asks the company to spend money. Merging them would mean a storekeeper handing over
    four gaskets went through the same approval as a forty-thousand order.

    "READY TO ISSUE" IS THE VIEW THE STORE LIVES IN. Approved, or part issued and still owing — the
    counter's actual worklist, which is not the same as everything open and not the same as
    everything waiting on a decision.

    SORTED BY WHEN IT IS NEEDED, undated last. A store queue ordered by when things were typed is a
    store queue that serves the patient before the urgent.

    THERE IS NO QUANTITY COLUMN. A request is many lines and one number could only be a line count;
    what the store needs to know at a glance is which project, how soon, and how far along it is.

    Expects: $materialRequests, $statuses, $projects, $warehouses, $priorities, $views, $filters,
             $canCreate
--}}
<x-layouts.app title="Material requests">

    <x-page-header
        title="Material requests"
        description="What the work needs from the stores, and how much of it has gone out.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('material-requests.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise a request
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="material-requests.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="material-requests.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title or purpose">
        <x-slot:extra>
            <div>
                <label for="warehouse" class="label">Store</label>
                <x-select name="warehouse" :options="$warehouses" :selected="$filters['warehouse']"
                          placeholder="Any store"/>
            </div>
            <div>
                <label for="priority" class="label">Priority</label>
                <x-select name="priority" :options="$priorities" :selected="$filters['priority']"
                          placeholder="Any priority"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$materialRequests" icon="clipboard"
                      title="No material requests"
                      description="Nothing has been asked of the stores that your access reaches. Requests follow the unit that raised them.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('material-requests.create') }}" class="btn btn-primary btn-sm">Raise a request</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Request</th>
                <th>Store</th>
                <th>Project</th>
                <th class="text-right">Lines</th>
                <th>Priority</th>
                <th>Needed by</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($materialRequests as $materialRequest)
                <tr>
                    <td>
                        <a href="{{ route('material-requests.show', $materialRequest) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $materialRequest->title }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $materialRequest->number }}
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $materialRequest->warehouse?->name ?: 'Any store' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $materialRequest->project?->name ?: '—' }}
                    </td>

                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">
                        {{ $materialRequest->items_count }}
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($materialRequest->priority)
                            {{-- Styled by position in the company's own list, not by a word this
                                 code claims to recognise — same rule as the requisition register. --}}
                            <span @class([
                                'font-medium text-rose-600' => $materialRequest->priority === array_key_last($priorities),
                                'text-[var(--text-body)]' => $materialRequest->priority !== array_key_last($priorities),
                            ])>
                                {{ $priorities[$materialRequest->priority] ?? $materialRequest->priority }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if ($materialRequest->required_by)
                            <span @class([
                                'font-medium text-rose-600' => $materialRequest->required_by->isPast()
                                    && ! $materialRequest->status?->is_terminal,
                                'text-[var(--text-muted)]' => ! $materialRequest->required_by->isPast()
                                    || $materialRequest->status?->is_terminal,
                            ])>
                                {{ $materialRequest->required_by->format('j M Y') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Not stated</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($materialRequest->status)
                            <x-badge :tone="$materialRequest->status->tone()">{{ $materialRequest->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
