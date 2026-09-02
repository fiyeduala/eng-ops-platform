@props([
    'name' => 'items',
    'columns' => [],
    'rows' => [],
    'addLabel' => 'Add a line',
    'quantityKey' => null,
    'rateKey' => null,
    'currency' => null,
    'minRows' => 1,
    'fixed' => false,
])

{{--
    The repeatable line grid every procurement register needs.

    FIVE REGISTERS, ONE EDITOR. A requisition, an enquiry, a quotation, an order and a delivery note
    are all a header plus a list of things, and hand-writing five of these would be five chances to
    get the index arithmetic subtly wrong — which produces the worst bug this module can have, the
    one where line 3 quietly saves over line 2.

    THE `id` FIELD IS POSTED BUT NEVER TRUSTED. Alpine carries it so an edit updates the row it came
    from rather than deleting and re-creating it. The service then looks that id up among the
    PARENT record's own lines; an id it does not recognise is treated as a new line rather than as
    an attack, because the honest cause — two people with the form open — is much the commoner one.
    An id belonging to another record, or another company, simply never matches (§84).

    LINE NUMBERS ARE NOT AN INPUT. They come from array order and are renumbered from 1 on save, so
    removing the middle line cannot leave a gap and nobody can post two line 4s.

    THE RUNNING TOTAL IS DECORATION. It is arithmetic done in the browser for the person typing.
    Every figure that reaches the database is recomputed server-side from the quantity and the rate,
    and none of the five form requests will accept a posted total.

    `fixed` DROPS THE ADD AND REMOVE CONTROLS. A goods receipt has exactly the lines its purchase
    order has — inventing a sixth line on a five-line order is not data entry, it is a discrepancy,
    and it belongs in the rejection reason rather than in a new row.

    Expects: $columns as [key, label, type, options?, step?, min?, width?, placeholder?, help?,
             default?], $rows as the existing lines (arrays or models carrying those keys).
--}}

@php
    // Old input wins: a validation failure must not throw away what somebody typed. Failing that,
    // seed from the records passed in, and pad with blanks so a create form opens with somewhere to
    // type rather than an empty grid and a button.
    $blank = ['id' => null];

    foreach ($columns as $column) {
        $blank[$column['key']] = $column['default'] ?? null;
    }

    $seed = old($name);

    if ($seed === null) {
        $seed = collect($rows)->map(function ($row) use ($columns) {
            $line = ['id' => data_get($row, 'id')];

            foreach ($columns as $column) {
                $value = data_get($row, $column['key']);

                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }

                $line[$column['key']] = is_bool($value) ? (int) $value : $value;
            }

            return $line;
        })->values()->all();
    } else {
        $seed = array_values($seed);
    }

    while (count($seed) < ($fixed ? 0 : $minRows)) {
        $seed[] = $blank;
    }

    $visible = array_values(array_filter($columns, fn ($column) => ($column['type'] ?? 'text') !== 'hidden'));
    $hidden = array_values(array_filter($columns, fn ($column) => ($column['type'] ?? 'text') === 'hidden'));
    $showsTotals = $quantityKey !== null && $rateKey !== null;
@endphp

<div x-data="{
        prefix: @js($name),
        lines: @js($seed),
        blank: @js($blank),
        add() { this.lines.push({ ...this.blank }); },
        remove(index) {
            this.lines.splice(index, 1);
            if (this.lines.length === 0) { this.add(); }
        },
        money(value) {
            return (value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        lineTotal(line) {
@if ($showsTotals)
            return this.money((parseFloat(line[@js($quantityKey)]) || 0) * (parseFloat(line[@js($rateKey)]) || 0));
@else
            return '';
@endif
        },
        get grandTotal() {
@if ($showsTotals)
            return this.money(this.lines.reduce((sum, line) =>
                sum + (parseFloat(line[@js($quantityKey)]) || 0) * (parseFloat(line[@js($rateKey)]) || 0), 0));
@else
            return '';
@endif
        },
     }"
     class="space-y-3">

    {{-- A message about line 3 lands on `items.2.quantity`, which is not the name of anything on the
         page once a row above it has been removed. Listing them together above the grid is honest
         about that: the reader is told which line and what, instead of hunting for a red border. --}}
    @if ($errors->has($name) || count($errors->get($name.'.*')) > 0)
        <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-200">
            <ul class="list-inside list-disc space-y-0.5">
                @foreach ($errors->get($name) as $message)
                    <li>{{ $message }}</li>
                @endforeach
                @foreach ($errors->get($name.'.*') as $messages)
                    @foreach ($messages as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    @endif

    <div class="overflow-x-auto rounded-md border border-[var(--surface-border)]">
        <table class="w-full min-w-[44rem] text-sm">
            <thead class="bg-[var(--surface-muted)] text-xs uppercase tracking-wide text-[var(--text-muted)]">
                <tr>
                    <th class="w-8 px-2 py-2 text-left font-medium">#</th>
                    @foreach ($visible as $column)
                        <th class="px-2 py-2 text-left font-medium {{ $column['width'] ?? '' }}">
                            {{ $column['label'] }}
                        </th>
                    @endforeach
                    @if ($showsTotals)
                        <th class="w-28 px-2 py-2 text-right font-medium">Line total</th>
                    @endif
                    @unless ($fixed)
                        <th class="w-8 px-2 py-2"><span class="sr-only">Remove</span></th>
                    @endunless
                </tr>
            </thead>

            <tbody class="divide-y divide-[var(--surface-border)]">
                <template x-for="(line, index) in lines" :key="index">
                    <tr class="align-top">
                        {{-- The hidden fields live inside a cell rather than loose in the row: an
                             input placed directly in a <tr> is hoisted out of the table by the HTML
                             parser and silently stops posting. --}}
                        <td class="px-2 py-3 text-xs text-[var(--text-muted)]">
                            <span x-text="index + 1"></span>
                            <input type="hidden" :name="`${prefix}[${index}][id]`" :value="line.id ?? ''">
                            @foreach ($hidden as $column)
                                <input type="hidden"
                                       :name="`${prefix}[${index}][{{ $column['key'] }}]`"
                                       :value="line.{{ $column['key'] }} ?? ''">
                            @endforeach
                        </td>

                        @foreach ($visible as $column)
                            <td class="px-2 py-2">
                                @switch($column['type'] ?? 'text')
                                    @case('select')
                                        <select :name="`${prefix}[${index}][{{ $column['key'] }}]`"
                                                x-model="line.{{ $column['key'] }}"
                                                class="select text-sm">
                                            <option value="">{{ $column['placeholder'] ?? '—' }}</option>
                                            @foreach (($column['options'] ?? []) as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @break

                                    @case('checkbox')
                                        {{-- The unchecked box posts nothing, so the 0 goes first and
                                             the checkbox overwrites it. Without the pair, clearing a
                                             flag on an edit form would leave it set. --}}
                                        <label class="flex items-center gap-2 py-2 text-xs text-[var(--text-muted)]">
                                            <input type="hidden" :name="`${prefix}[${index}][{{ $column['key'] }}]`" value="0">
                                            <input type="checkbox" value="1"
                                                   :name="`${prefix}[${index}][{{ $column['key'] }}]`"
                                                   x-model="line.{{ $column['key'] }}"
                                                   class="h-4 w-4 rounded border-[var(--surface-border)]">
                                            <span>{{ $column['help'] ?? 'Yes' }}</span>
                                        </label>
                                        @break

                                    @case('textarea')
                                        <textarea :name="`${prefix}[${index}][{{ $column['key'] }}]`"
                                                  x-model="line.{{ $column['key'] }}"
                                                  rows="2"
                                                  placeholder="{{ $column['placeholder'] ?? '' }}"
                                                  class="textarea text-sm"></textarea>
                                        @break

                                    @case('static')
                                        {{-- Shown, not editable, and posted only where the server is
                                             going to ignore it anyway — a receipt shows what was
                                             ordered so the storeman can count against it. --}}
                                        <span class="block py-2 text-sm text-[var(--text-body)]"
                                              x-text="line.{{ $column['key'] }} ?? '—'"></span>
                                        @break

                                    @default
                                        <input type="{{ $column['type'] ?? 'text' }}"
                                               :name="`${prefix}[${index}][{{ $column['key'] }}]`"
                                               x-model="line.{{ $column['key'] }}"
                                               @isset($column['step']) step="{{ $column['step'] }}" @endisset
                                               @isset($column['min']) min="{{ $column['min'] }}" @endisset
                                               placeholder="{{ $column['placeholder'] ?? '' }}"
                                               class="input text-sm">
                                @endswitch
                            </td>
                        @endforeach

                        @if ($showsTotals)
                            <td class="whitespace-nowrap px-2 py-3 text-right font-mono text-sm text-[var(--text-muted)]"
                                x-text="lineTotal(line)"></td>
                        @endif

                        @unless ($fixed)
                            <td class="px-2 py-3 text-right">
                                <button type="button" @click="remove(index)"
                                        class="text-[var(--text-muted)] transition hover:text-rose-600"
                                        :aria-label="`Remove line ${index + 1}`">
                                    <x-icon name="trash" class="h-4 w-4"/>
                                </button>
                            </td>
                        @endunless
                    </tr>
                </template>

                @if ($fixed)
                    <tr x-show="lines.length === 0">
                        <td colspan="{{ count($visible) + 1 + ($showsTotals ? 1 : 0) }}"
                            class="px-3 py-6 text-center text-sm text-[var(--text-muted)]">
                            Nothing to receive against — every line on this order has already arrived.
                        </td>
                    </tr>
                @endif
            </tbody>

            @if ($showsTotals)
                <tfoot class="bg-[var(--surface-muted)]">
                    <tr>
                        <td colspan="{{ count($visible) + 1 }}"
                            class="px-2 py-2 text-right text-xs uppercase tracking-wide text-[var(--text-muted)]">
                            Total{{ $currency ? ' ('.$currency.')' : '' }}
                        </td>
                        <td class="whitespace-nowrap px-2 py-2 text-right font-mono text-sm font-medium text-[var(--text-strong)]"
                            x-text="grandTotal"></td>
                        @unless ($fixed) <td></td> @endunless
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @unless ($fixed)
        <button type="button" @click="add()" class="btn btn-secondary btn-sm">
            <x-icon name="plus" class="h-3.5 w-3.5"/> {{ $addLabel }}
        </button>
    @endunless

    @if ($showsTotals)
        <p class="text-xs text-[var(--text-muted)]">
            The figure above is your browser's arithmetic, for you. What gets stored is recalculated
            from the lines when you save.
        </p>
    @endif
</div>
