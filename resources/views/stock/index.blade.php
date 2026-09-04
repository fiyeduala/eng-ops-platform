{{--
    The stock ledger — every movement, in the order it happened.

    THIS PAGE IS A BANK STATEMENT, not a register of things people work on. There is no "my" view,
    no draft, no status chip and no edit pencil, because a movement has no lifecycle: it happened.
    The only correction is another row, which is why the actions here are five verbs rather than a
    "New movement" button with a type dropdown — the verb lives in the URL and each one demands its
    own permission (§84).

    THE SIGN IS RENDERED AS A COLOUR AND A SIGN, never as a direction column. The ledger stores
    quantities signed; the screen shows +40 in green and −40 in red and the type word beside it, so
    a page read quickly still says which way the stock went.

    VALUE IS BEHIND ITS OWN PERMISSION. A storekeeper counts things; what they cost is a separate
    question with a separate grant, and the column simply is not drawn for somebody who lacks it
    (§68). Note it is dropped from the header too — a blank column advertises what is being kept.

    Expects: $movements, $warehouses, $projects, $types, $filters,
             $canSeeValue, $canPost, $canTransfer, $canAdjust
--}}
<x-layouts.app title="Stock movements">

    <x-page-header
        title="Stock movements"
        description="Every receipt, issue, transfer and correction, in the order it happened.">
        <x-slot:actions>
            @if ($canPost)
                <a href="{{ route('stock.create', 'receive') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Receive
                </a>
                <a href="{{ route('stock.create', 'issue') }}" class="btn btn-secondary btn-sm">Issue</a>
                <a href="{{ route('stock.create', 'return') }}" class="btn btn-secondary btn-sm">Return</a>
            @endif
            @if ($canTransfer)
                <a href="{{ route('stock.create', 'transfer') }}" class="btn btn-secondary btn-sm">Transfer</a>
            @endif
            @if ($canAdjust)
                <a href="{{ route('stock.create', 'count') }}" class="btn btn-secondary btn-sm">Count</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- The shared filter bar, minus its status axis: the ledger has no statuses to filter on.
         Store, movement kind and a date window take that space instead. --}}
    <x-register.filters route="stock.index" :filters="$filters" :projects="$projects"
                        placeholder="Movement number, reason or batch">
        <x-slot:extra>
            <div>
                <label for="warehouse" class="label">Store</label>
                <x-select name="warehouse" :options="$warehouses" :selected="$filters['warehouse']"
                          placeholder="Any store"/>
            </div>
            <div>
                <label for="type" class="label">Movement</label>
                <x-select name="type" :options="$types" :selected="$filters['type']"
                          placeholder="Any movement"/>
            </div>
            <div>
                <label for="from" class="label">From</label>
                <input type="date" name="from" id="from" class="input" value="{{ $filters['from'] }}">
            </div>
            <div>
                <label for="to" class="label">To</label>
                <input type="date" name="to" id="to" class="input" value="{{ $filters['to'] }}">
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$movements" icon="truck"
                      title="No movements"
                      description="Nothing has moved in the stores your access reaches, or the filters above are narrower than the ledger.">
        <x-slot:head>
            <tr>
                <th>When</th>
                <th>Movement</th>
                <th>Item</th>
                <th>Store</th>
                <th class="text-right">Quantity</th>
                @if ($canSeeValue)
                    <th class="text-right">Value</th>
                @endif
                <th>By</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($movements as $movement)
                @php $inbound = $movement->isInbound(); @endphp
                <tr>
                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        {{ $movement->occurred_at?->format('j M Y') }}
                        <span class="block text-xs">{{ $movement->occurred_at?->format('H:i') }}</span>
                    </td>

                    <td>
                        <a href="{{ route('stock.show', $movement) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $types[$movement->type] ?? $movement->type }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $movement->number }}
                        </span>
                    </td>

                    <td class="text-sm">
                        <span class="text-[var(--text-body)]">{{ $movement->stockItem?->name }}</span>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $movement->stockItem?->code }}
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $movement->warehouse?->name ?: '—' }}
                    </td>

                    {{-- The sign is already in the stored number. Formatting it with a forced plus
                         on the way in keeps the two halves of a transfer legible side by side. --}}
                    <td @class([
                        'whitespace-nowrap text-right font-mono text-sm font-medium',
                        'text-emerald-600' => $inbound,
                        'text-rose-600' => ! $inbound,
                    ])>
                        {{ $inbound ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $movement->quantity, 4, '.', ','), '0'), '.') }}
                        <span class="text-xs font-normal text-[var(--text-muted)]">
                            {{ $movement->stockItem?->uom }}
                        </span>
                    </td>

                    @if ($canSeeValue)
                        <td class="whitespace-nowrap text-right font-mono text-sm text-[var(--text-muted)]">
                            {{ $movement->value !== null ? number_format((float) $movement->value, 2) : '—' }}
                        </td>
                    @endif

                    <td class="text-sm text-[var(--text-muted)]">{{ $movement->creator?->name ?: '—' }}</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
