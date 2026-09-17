@use('App\Support\Quantity')
{{--
    One delivery.

    THE FOUR ACTS ARE NOT STATUS CHIPS. Confirming, inspecting, accepting and disputing each write
    something the status alone cannot hold — a receiver and a time, an inspector and a time, an
    acceptance that releases the goods, a dispute that keeps the order from reading as satisfied.
    They are separate forms with their own permissions, and `x-register.transitions` sits underneath
    for the movements that are only a change of state.

    ACCEPTING IS SOMEBODY ELSE'S JOB. `goods_receipt.approve` is a different grant from
    `goods_receipt.edit`, so the storeman who counted the pallet is not usually the person who
    accepts it. The service enforces the rest; this page simply does not show a button the reader
    cannot use (§6 — and the button's absence is a courtesy, not the control).

    A REFUSED QUANTITY CARRIES ITS REASON, and the reason is shown on the line rather than buried in
    the notes, because that sentence is what a debit note to the supplier will be argued from.

    Expects: $goodsReceipt, $summary, $timeline, $transitions, $workflowInstance,
             $canEdit, $canDelete, $canConfirm, $canInspect, $canApprove
--}}
<x-layouts.app :title="$goodsReceipt->number">

    <x-page-header
        :title="$goodsReceipt->number"
        :description="$goodsReceipt->purchaseOrder
            ? 'Delivered against '.$goodsReceipt->purchaseOrder->number
                .($goodsReceipt->purchaseOrder->vendor ? ' by '.$goodsReceipt->purchaseOrder->vendor->name : '')
            : 'A delivery.'">
        <x-slot:actions>
            @if ($goodsReceipt->purchaseOrder)
                <a href="{{ route('purchase-orders.show', $goodsReceipt->purchaseOrder) }}"
                   class="btn btn-secondary btn-sm">
                    <x-icon name="cart" class="h-3.5 w-3.5"/> The order
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('goods-receipts.edit', $goodsReceipt) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($goodsReceipt->isDisputed())
        <div class="card border-rose-300 bg-rose-50/60 p-5 dark:border-rose-900 dark:bg-rose-950/30">
            <p class="text-sm font-medium text-rose-900 dark:text-rose-200">This delivery is disputed.</p>
            <p class="mt-1 text-sm text-rose-800 dark:text-rose-300">
                The order will not read as satisfied by these goods until the dispute is resolved and
                the delivery accepted.
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">The delivery</h2>
                </div>

                <dl class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-register.fact label="Order">
                        @if ($goodsReceipt->purchaseOrder)
                            <a href="{{ route('purchase-orders.show', $goodsReceipt->purchaseOrder) }}"
                               class="font-mono hover:text-brand-600">{{ $goodsReceipt->purchaseOrder->number }}</a>
                        @else
                            —
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Supplier" :value="$goodsReceipt->purchaseOrder?->vendor?->name"/>
                    <x-register.fact label="Their delivery note"
                                     :value="$goodsReceipt->delivery_note_ref" mono/>
                    <x-register.fact label="Received at" :value="$goodsReceipt->location?->name"/>
                    <x-register.fact label="Carrier" :value="$goodsReceipt->carrier"/>
                    <x-register.fact label="Vehicle" :value="$goodsReceipt->vehicle_ref" mono/>
                    {{-- Where the goods ended up, which is a different question from where the
                         lorry stopped. Shown to everybody who can read the receipt: the store's
                         NAME is not a value, and somebody chasing a delivery needs to know which
                         shelf to look on. --}}
                    <x-register.fact label="Into store" :value="$goodsReceipt->warehouse?->name"/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">What was counted</h2>
                    <p class="text-sm text-[var(--text-muted)]">
                        Only the lines something arrived against are recorded here.
                    </p>
                </div>

                @if ($goodsReceipt->items->isEmpty())
                    <div class="p-5">
                        <x-empty-state icon="package" title="Nothing counted yet"
                                       description="No quantities have been entered against this delivery."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                    <th class="px-5 py-3">Item</th>
                                    <th class="px-5 py-3 text-right">Accepted</th>
                                    <th class="px-5 py-3 text-right">Refused</th>
                                    <th class="px-5 py-3">Condition</th>
                                    <th class="px-5 py-3">Batch</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--surface-border)]">
                                @foreach ($goodsReceipt->items as $item)
                                    <tr>
                                        <td class="px-5 py-3 align-top">
                                            <span class="text-[var(--text-strong)]">
                                                {{ $item->purchaseOrderItem?->description ?: 'Ordered item' }}
                                            </span>
                                            @if ($item->notes)
                                                <span class="mt-0.5 block text-xs text-[var(--text-muted)]">{{ $item->notes }}</span>
                                            @endif
                                            @if ($item->asset)
                                                <a href="{{ route('assets.show', $item->asset) }}"
                                                   class="mt-2 block text-sm text-brand-600 hover:underline">Asset {{ $item->asset->code }}</a>
                                            @elseif ($goodsReceipt->statusKey() === 'accepted' && (float) $item->quantity_accepted === 1.0)
                                                @can ('create', \App\Models\Asset::class)
                                                    @can ('view', $goodsReceipt->purchaseOrder)
                                                        <a href="{{ route('assets.from-receipt.create', $item) }}"
                                                           class="mt-2 block text-sm text-brand-600 hover:underline">Register as an asset</a>
                                                    @endcan
                                                @endcan
                                            @endif
                                            @if ($item->projectCost)
                                                <a href="{{ route('project-costs.show', $item->projectCost) }}"
                                                   class="mt-2 block text-sm text-brand-600 hover:underline">Cost {{ $item->projectCost->number }}</a>
                                            @endif
                                            @if ((float) $item->quantity_rejected > 0 && $item->rejection_reason)
                                                {{-- The sentence a debit note gets argued from. --}}
                                                <span class="mt-1 block text-xs text-rose-700 dark:text-rose-300">
                                                    Refused: {{ $item->rejection_reason }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right align-top font-mono">
                                            {{ Quantity::withUnit($item->quantity_accepted, $item->purchaseOrderItem?->uom) }}
                                        </td>
                                        <td class="px-5 py-3 text-right align-top font-mono {{ (float) $item->quantity_rejected > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-[var(--text-muted)]' }}">
                                            {{ Quantity::withUnit($item->quantity_rejected, $item->purchaseOrderItem?->uom) }}
                                        </td>
                                        <td class="px-5 py-3 align-top text-[var(--text-muted)]">{{ $item->condition ?: '—' }}</td>
                                        <td class="px-5 py-3 align-top font-mono text-xs text-[var(--text-muted)]">
                                            {{ $item->batch_ref ?: '—' }}
                                            @if ($item->expires_on)
                                                <span class="block">exp {{ $item->expires_on->format('j M Y') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if ($goodsReceipt->inspection_notes || $goodsReceipt->notes)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Notes</h2>
                    </div>

                    <div class="space-y-4 p-5 text-sm text-[var(--text-body)]">
                        @if ($goodsReceipt->inspection_notes)
                            <div>
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">What the inspection found</p>
                                <p class="mt-1 whitespace-pre-line">{{ $goodsReceipt->inspection_notes }}</p>
                            </div>
                        @endif
                        @if ($goodsReceipt->notes)
                            <div>
                                <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Anything else</p>
                                <p class="mt-1 whitespace-pre-line">{{ $goodsReceipt->notes }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            @php
                // The acts are offered by where the receipt has got to, not by what a status is
                // called elsewhere: a delivery is confirmed once, inspected after that, and accepted
                // or disputed by somebody with the standing to do it.
                $key = $goodsReceipt->status?->key;
            @endphp

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Standing</h2>
                </div>

                <dl class="space-y-4 p-5">
                    <x-register.fact label="Status">
                        @if ($goodsReceipt->status)
                            <x-badge :tone="$goodsReceipt->status->tone()">{{ $goodsReceipt->status->label }}</x-badge>
                        @else
                            —
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Lines counted" :value="(string) $summary['lines']"/>
                    <x-register.fact label="Accepted" :value="Quantity::trim($summary['accepted'])" mono/>
                    <x-register.fact label="Refused" :value="Quantity::trim($summary['rejected'])" mono/>
                    <x-register.fact label="Received">
                        @if ($goodsReceipt->received_at)
                            {{ $goodsReceipt->received_at->format('j M Y, H:i') }}
                            @if ($goodsReceipt->receiver)
                                <span class="block text-xs text-[var(--text-muted)]">by {{ $goodsReceipt->receiver->name }}</span>
                            @endif
                        @else
                            Not yet confirmed
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Inspected">
                        @if ($goodsReceipt->inspected_at)
                            {{ $goodsReceipt->inspected_at->format('j M Y, H:i') }}
                            @if ($goodsReceipt->inspector)
                                <span class="block text-xs text-[var(--text-muted)]">by {{ $goodsReceipt->inspector->name }}</span>
                            @endif
                        @else
                            Not inspected
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Entered by" :value="$goodsReceipt->creator?->name"/>
                    <x-register.fact label="Confidentiality"
                                     :value="ucfirst($goodsReceipt->confidentiality?->value ?? 'internal')"/>
                </dl>

                @if ($canConfirm && $key === 'draft')
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Confirming records you as the receiver, at this moment, and lets the order
                            count these goods.
                        </p>
                        <form method="POST" action="{{ route('goods-receipts.confirm', $goodsReceipt) }}" class="mt-3">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm w-full">
                                <x-icon name="truck" class="h-3.5 w-3.5"/> Confirm the delivery
                            </button>
                        </form>
                    </div>
                @endif

                @if ($canInspect && $key === 'received')
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('goods-receipts.inspect', $goodsReceipt) }}" class="space-y-3">
                            @csrf
                            <x-field label="Record the inspection" name="comment"
                                     help="What was checked, and what it showed.">
                                <x-textarea name="comment" rows="3"/>
                            </x-field>
                            <button type="submit" class="btn btn-secondary btn-sm w-full">
                                <x-icon name="check-square" class="h-3.5 w-3.5"/> Record the inspection
                            </button>
                        </form>
                    </div>
                @endif

                @if ($canApprove && in_array($key, ['received', 'inspected', 'disputed'], true))
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('goods-receipts.accept', $goodsReceipt) }}" class="space-y-3">
                            @csrf
                            <x-field label="Accept the delivery" name="comment"
                                     help="Accepting is the release: the goods are ours and the order counts them.">
                                <x-textarea name="comment" rows="2"/>
                            </x-field>
                            <button type="submit" class="btn btn-primary btn-sm w-full">
                                <x-icon name="check" class="h-3.5 w-3.5"/> Accept
                            </button>
                        </form>
                    </div>
                @endif

                @if ($canInspect && in_array($key, ['received', 'inspected'], true))
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('goods-receipts.dispute', $goodsReceipt) }}" class="space-y-3">
                            @csrf
                            <x-field label="Raise a dispute" name="reason" required
                                     help="Say what is wrong. This is what the supplier will be shown.">
                                <x-textarea name="reason" rows="3"/>
                            </x-field>
                            <button type="submit" class="btn btn-danger btn-sm w-full">
                                <x-icon name="alert" class="h-3.5 w-3.5"/> Raise a dispute
                            </button>
                        </form>
                    </div>
                @endif

                <x-register.transitions :action="route('goods-receipts.transition', $goodsReceipt)"
                                        :transitions="$transitions" noun="delivery"/>
            </div>

            @if ($workflowInstance)
                <x-workflow-progress :instance="$workflowInstance"/>
            @endif

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this delivery yet."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Delete</h2>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            A delivery booked against the wrong order is deleted and entered again. The
                            order's received quantities are recomputed without it.
                        </p>
                        <x-delete-form :action="route('goods-receipts.destroy', $goodsReceipt)"
                                       label="Delete this receipt"
                                       confirm="The order will stop counting these goods. Continue?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
