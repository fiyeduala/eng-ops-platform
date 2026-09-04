{{--
    One movement.

    A LEDGER ROW HAS NO EDIT BUTTON, and this page is where that is felt rather than merely
    enforced. There is no pencil, no delete and no status control: the only thing anybody can do to
    a movement that has already happened is post the opposite one against it, and that is what the
    reversal card offers. The model, the policy and the permission catalogue all refuse the other
    three routes independently (§59) — the missing button is the fourth, and the least important.

    A REVERSED MOVEMENT IS SHOWN, NOT HIDDEN. Two banners cover the two halves of a correction: this
    row was corrected by a later one, or this row IS the correction. Either way both rows stay in
    the ledger and both stay legible, because "where did the nine go" is answered by a pair of rows
    and never by an absence.

    Expects: $movement, $timeline, $canSeeValue, $canReverse
--}}
<x-layouts.app :title="$movement->number">

    <x-page-header :title="$movement->movementLabel()"
                   :description="$movement->stockItem?->name.' · '.$movement->warehouse?->name">
        <x-slot:actions>
            <span class="font-mono text-xs text-[var(--text-muted)]">{{ $movement->number }}</span>
            <a href="{{ route('stock.index') }}" class="btn btn-ghost btn-sm">All movements</a>
        </x-slot:actions>
    </x-page-header>

    @if ($movement->isReversed())
        <div class="card mb-5 border-amber-300 bg-amber-50 p-4 text-sm text-amber-900
                    dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            This movement has been corrected by a later reversal. It stays in the ledger because the
            balance it produced was real at the time; the reversal is the row that undid it.
        </div>
    @endif

    @if ($movement->reverses)
        <div class="card mb-5 border-[var(--border)] bg-[var(--surface-2)] p-4 text-sm text-[var(--text-body)]">
            This is a correction. It reverses
            <a href="{{ route('stock.show', $movement->reverses) }}"
               class="font-mono text-brand-600 hover:underline">{{ $movement->reverses->number }}</a>,
            which remains on the ledger.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card p-5">
                <h3 class="card-title mb-4">The movement</h3>
                <dl class="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                    <x-register.fact label="Item">
                        <a href="{{ route('stock-items.show', $movement->stock_item_id) }}"
                           class="text-brand-600 hover:underline">{{ $movement->stockItem?->name }}</a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $movement->stockItem?->code }}
                        </span>
                    </x-register.fact>

                    <x-register.fact label="Store">
                        <a href="{{ route('warehouses.show', $movement->warehouse_id) }}"
                           class="text-brand-600 hover:underline">{{ $movement->warehouse?->name }}</a>
                    </x-register.fact>

                    <x-register.fact label="Quantity">
                        <span @class([
                            'font-mono font-medium',
                            'text-emerald-600' => $movement->isInbound(),
                            'text-rose-600' => ! $movement->isInbound(),
                        ])>
                            {{ $movement->isInbound() ? '+' : '' }}{{ (float) $movement->quantity }}
                            {{ $movement->stockItem?->uom }}
                        </span>
                    </x-register.fact>

                    {{-- The balance the store held the instant after this row was written. Kept on
                         the row rather than recomputed, so a page opened next year still shows what
                         the storekeeper saw. --}}
                    <x-register.fact label="Balance after" mono
                                     :value="$movement->balance_after !== null ? (float) $movement->balance_after.' '.$movement->stockItem?->uom : null"/>

                    @if ($canSeeValue)
                        <x-register.fact label="Unit cost" mono
                                         :value="$movement->unit_cost !== null ? number_format((float) $movement->unit_cost, 4) : null"/>
                        <x-register.fact label="Value" mono
                                         :value="$movement->value !== null ? number_format((float) $movement->value, 2) : null"/>
                    @endif
                </dl>
            </div>

            @if (filled($movement->reason) || filled($movement->notes))
                <div class="card p-5">
                    <h3 class="card-title mb-3">Why</h3>
                    @if (filled($movement->reason))
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $movement->reason }}</p>
                    @endif
                    @if (filled($movement->notes))
                        <p class="mt-3 whitespace-pre-line text-sm text-[var(--text-muted)]">{{ $movement->notes }}</p>
                    @endif
                </div>
            @endif

            @if ($canReverse)
                {{-- The only write this page offers. A reason is demanded because a reversal is a
                     statement that something was recorded wrongly, and an unexplained one leaves
                     the next reader with two rows and no account of either. --}}
                <div class="card p-5">
                    <h3 class="card-title mb-2">Correct this movement</h3>
                    <p class="mb-4 text-sm text-[var(--text-muted)]">
                        This writes an opposite movement against {{ $movement->number }}. Both rows
                        stay on the ledger — nothing is edited and nothing is removed.
                    </p>

                    <form method="POST" action="{{ route('stock.reverse', $movement) }}" class="space-y-3">
                        @csrf
                        <x-field label="Reason for the correction" name="reason" required
                                 help="What was wrong with the original entry.">
                            <x-textarea name="reason" rows="3" required/>
                        </x-field>
                        <button type="submit" class="btn btn-danger btn-sm"
                                onclick="return confirm('Post a reversal against {{ $movement->number }}?')">
                            Post the reversal
                        </button>
                    </form>
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has been recorded against this movement."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <h3 class="card-title mb-4">Particulars</h3>
                <dl class="space-y-4">
                    <x-register.fact label="Occurred"
                                     :value="$movement->occurred_at?->format('j M Y, H:i')"/>
                    <x-register.fact label="Recorded by" :value="$movement->creator?->name"/>
                    <x-register.fact label="Entered"
                                     :value="$movement->created_at?->format('j M Y, H:i')"/>
                    <x-register.fact label="Project" :value="$movement->project?->name"/>
                    <x-register.fact label="Unit" :value="$movement->orgUnit?->name"/>
                    <x-register.fact label="Batch" :value="$movement->batch_ref" mono/>
                    <x-register.fact label="Expires" :value="$movement->expires_on?->format('j M Y')"/>
                </dl>
            </div>

            {{-- WHAT CAUSED IT — the §90 thread, read backwards. A receipt posted by an accepted
                 delivery names that delivery, so the trail from a shelf back to the requisition is
                 walkable one page at a time. Rendered as text rather than a link: the identity is
                 part of this movement's provenance, but which register it lives in is that
                 register's business to authorize. --}}
            @if ($movement->source_type)
                <div class="card p-5">
                    <h3 class="card-title mb-4">What caused it</h3>
                    <dl class="space-y-4">
                        <x-register.fact label="Source"
                                         :value="Str::headline($movement->source_type)"/>
                        <x-register.fact label="Reference" mono
                                         :value="$movement->source?->number ?? '#'.$movement->source_id"/>
                    </dl>
                </div>
            @endif

            @if ($movement->transfer_ref)
                <div class="card p-5">
                    <h3 class="card-title mb-2">Part of a transfer</h3>
                    <p class="text-sm text-[var(--text-muted)]">
                        Stock moving between two stores is written as two rows — one out, one in —
                        sharing a reference, so neither store's ledger has a gap in it.
                    </p>
                    <p class="mt-3 font-mono text-xs text-[var(--text-muted)]">{{ $movement->transfer_ref }}</p>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
