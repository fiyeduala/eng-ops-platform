@use('App\Support\Quantity')
{{--
    One purchase order — and the point in the chain where a commitment becomes goods on a lorry.

    THE PROGRESS BAR IS ARITHMETIC OVER THE RECEIPTS. Ordered, accepted and outstanding are counted
    from the goods receipt lines, never from a field anybody can type. That is why there is no
    "mark as received" anywhere on this page: a person who could type it could pay for goods that
    never came.

    APPROVE, REJECT, ISSUE AND CANCEL ARE NOT STATUS CHIPS. Each is a distinct authority held by a
    different person, each carries a comment, and the service refuses an approval by whoever
    submitted it regardless of which buttons this page decided to draw (§84).

    APPROVED AND ISSUED ARE DIFFERENT DAYS. Approval is internal authority; issue is the moment the
    supplier has the order and the company is committed. Collapsing them would lose the only
    evidence of when the commitment was actually made.

    Expects: $purchaseOrder, $progress, $timeline, $transitions, $workflowInstance, $canEdit,
             $canDelete, $canSubmit, $canApprove, $canReject, $canIssue, $canCancel, $canReceive,
             $needsJustification
--}}
<x-layouts.app :title="$purchaseOrder->number">

    <x-page-header
        :title="$purchaseOrder->title"
        :description="$purchaseOrder->number.' — '.($purchaseOrder->vendor?->name ?: 'supplier not recorded')">
        <x-slot:actions>
            @if ($canReceive)
                <a href="{{ route('goods-receipts.create', ['order' => $purchaseOrder->id]) }}"
                   class="btn btn-primary btn-sm">
                    <x-icon name="truck" class="h-3.5 w-3.5"/> Book a delivery
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('purchase-orders.edit', $purchaseOrder) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($needsJustification && $canApprove)
        {{-- Said before the click, not as a validation error afterwards. The rule itself lives in
             the service; this only decides what the page says (§84). --}}
        <div class="card mb-5 border-amber-300 p-4">
            <p class="text-sm text-[var(--text-body)]">
                <span class="font-medium text-amber-600">This order was not competed.</span>
                It is above the threshold your company set for obtaining quotations, and no awarded
                quotation sits behind it. Approving it needs a written reason — the sentence somebody
                reads in two years' time.
            </p>
        </div>
    @endif

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The order</h3>
                </div>
                <dl class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-register.fact label="Supplier" :value="$purchaseOrder->vendor?->name"/>
                    <x-register.fact label="Project" :value="$purchaseOrder->project?->name"/>
                    <x-register.fact label="Ordered on" :value="$purchaseOrder->ordered_on?->format('j M Y')"/>
                    <x-register.fact label="Required by">
                        @if ($purchaseOrder->required_by)
                            <span @class([
                                'font-medium text-rose-600' => $purchaseOrder->required_by->isPast()
                                    && $purchaseOrder->acceptsReceipts(),
                            ])>{{ $purchaseOrder->required_by->format('j M Y') }}</span>
                        @else
                            <span class="text-[var(--text-muted)]">Not stated</span>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Deliver to" :value="$purchaseOrder->location?->name"/>
                    <x-register.fact label="Owning unit" :value="$purchaseOrder->orgUnit?->name"/>
                    <x-register.fact label="Payment terms" :value="$purchaseOrder->payment_terms"/>
                    <x-register.fact label="Delivery terms" :value="$purchaseOrder->delivery_terms"/>

                    @if ($purchaseOrder->delivery_address)
                        <div class="sm:col-span-2">
                            <x-register.fact label="Delivery address" :value="$purchaseOrder->delivery_address"/>
                        </div>
                    @endif

                    @if ($purchaseOrder->notes)
                        <div class="sm:col-span-2">
                            <x-register.fact label="Notes" :value="$purchaseOrder->notes"/>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Lines, and what has arrived</h3>
                    <span class="text-xs text-[var(--text-muted)]">{{ $purchaseOrder->currency }}</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                            <tr>
                                <th class="px-5 py-2 font-medium">#</th>
                                <th class="px-3 py-2 font-medium">Description</th>
                                <th class="px-3 py-2 text-right font-medium">Ordered</th>
                                <th class="px-3 py-2 text-right font-medium">Accepted</th>
                                <th class="px-3 py-2 text-right font-medium">Outstanding</th>
                                <th class="px-3 py-2 text-right font-medium">Unit price</th>
                                <th class="px-5 py-2 text-right font-medium">Line total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--surface-border)]">
                            @foreach ($purchaseOrder->items as $item)
                                <tr>
                                    <td class="px-5 py-3 font-mono text-xs text-[var(--text-muted)]">{{ $item->line_no }}</td>
                                    <td class="px-3 py-3">
                                        <span class="text-[var(--text-body)]">{{ $item->description }}</span>
                                        @if ($item->specification)
                                            <span class="block text-xs text-[var(--text-muted)]">{{ $item->specification }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-muted)]">
                                        {{ Quantity::withUnit($item->quantity, $item->uom) }}
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-body)]">
                                        {{ Quantity::trim($item->acceptedQuantity()) }}
                                    </td>
                                    {{-- Outstanding is ordered minus accepted, counted from the
                                         receipt lines. Nobody types it and nobody can zero it. --}}
                                    <td class="px-3 py-3 text-right font-mono text-xs">
                                        @if ($item->isFullyReceived())
                                            <span class="text-emerald-600">—</span>
                                        @else
                                            <span class="text-amber-600">{{ Quantity::trim($item->outstandingQuantity()) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-body)]">
                                        {{ $item->unit_price !== null ? number_format((float) $item->unit_price, 2) : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono text-sm text-[var(--text-strong)]">
                                        {{ $item->computedTotal() !== null ? number_format((float) $item->computedTotal(), 2) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-[var(--surface-border)] text-sm">
                            <tr>
                                <td colspan="6" class="px-5 py-2 text-right text-[var(--text-muted)]">Subtotal</td>
                                <td class="px-5 py-2 text-right font-mono text-[var(--text-body)]">
                                    {{ number_format((float) $purchaseOrder->subtotal, 2) }}
                                </td>
                            </tr>
                            @if ((float) $purchaseOrder->tax_amount > 0)
                                <tr>
                                    <td colspan="6" class="px-5 py-2 text-right text-[var(--text-muted)]">Tax</td>
                                    <td class="px-5 py-2 text-right font-mono text-[var(--text-body)]">
                                        {{ number_format((float) $purchaseOrder->tax_amount, 2) }}
                                    </td>
                                </tr>
                            @endif
                            <tr>
                                <td colspan="6" class="px-5 py-3 text-right font-medium text-[var(--text-strong)]">
                                    Committed, {{ $purchaseOrder->currency }}
                                </td>
                                <td class="px-5 py-3 text-right font-mono font-medium text-[var(--text-strong)]">
                                    {{ number_format((float) $purchaseOrder->total, 2) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Deliveries against this order</h3>
                    @if ($canReceive)
                        <a href="{{ route('goods-receipts.create', ['order' => $purchaseOrder->id]) }}"
                           class="btn btn-secondary btn-sm">Book a delivery</a>
                    @endif
                </div>

                @if ($purchaseOrder->receipts->isEmpty())
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Nothing has arrived yet — or nothing has been booked in. The order stays
                            outstanding until a receipt says otherwise.
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($purchaseOrder->receipts as $receipt)
                            <li class="flex items-center justify-between gap-4 px-5 py-3">
                                <div>
                                    <a href="{{ route('goods-receipts.show', $receipt) }}"
                                       class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $receipt->number }}
                                    </a>
                                    <span class="block text-xs text-[var(--text-muted)]">
                                        {{ $receipt->received_at?->format('j M Y') ?: 'Date not recorded' }}
                                        @if ($receipt->receiver)
                                            · received by {{ $receipt->receiver->name }}
                                        @endif
                                        @if ($receipt->delivery_note_ref)
                                            · note {{ $receipt->delivery_note_ref }}
                                        @endif
                                    </span>
                                </div>
                                @if ($receipt->status)
                                    <x-badge :tone="$receipt->status->tone()">{{ $receipt->status->label }}</x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($purchaseOrder->quotation || $purchaseOrder->purchaseRequest || $purchaseOrder->contract)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Where this order came from</h3>
                    </div>
                    {{-- The §90 chain, read backwards: requisition → enquiry → award → this order.
                         Each link is a record somebody can open, not a copied reference number. --}}
                    <dl class="grid gap-4 p-5 sm:grid-cols-2">
                        @if ($purchaseOrder->purchaseRequest)
                            <x-register.fact label="Requisition">
                                <a href="{{ route('purchase-requests.show', $purchaseOrder->purchaseRequest) }}"
                                   class="font-medium hover:text-brand-600">
                                    {{ $purchaseOrder->purchaseRequest->number }} — {{ $purchaseOrder->purchaseRequest->title }}
                                </a>
                            </x-register.fact>
                        @endif

                        @if ($purchaseOrder->quotation)
                            <x-register.fact label="Awarded quotation">
                                <a href="{{ route('quotations.show', $purchaseOrder->quotation) }}"
                                   class="font-medium hover:text-brand-600">
                                    {{ $purchaseOrder->quotation->number }}{{ $purchaseOrder->quotation->reference ? ' — '.$purchaseOrder->quotation->reference : '' }}
                                </a>
                            </x-register.fact>
                        @endif

                        @if ($purchaseOrder->contract)
                            <x-register.fact label="Under contract">
                                <a href="{{ route('contracts.show', $purchaseOrder->contract) }}"
                                   class="font-medium hover:text-brand-600">
                                    {{ $purchaseOrder->contract->number }} — {{ $purchaseOrder->contract->title }}
                                </a>
                            </x-register.fact>
                        @endif
                    </dl>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Standing</h3>
                    @if ($purchaseOrder->status)
                        <x-badge :tone="$purchaseOrder->status->tone()">{{ $purchaseOrder->status->label }}</x-badge>
                    @endif
                </div>

                <div class="space-y-4 p-5">
                    {{-- Counted from the receipts, so the bar cannot disagree with the lines above
                         and neither can be typed. --}}
                    @php
                        $ordered = (float) $progress['ordered'];
                        $accepted = (float) $progress['accepted'];
                        $percent = $ordered > 0 ? min(100, round(($accepted / $ordered) * 100)) : 0;
                    @endphp

                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="text-[var(--text-muted)]">Delivered</span>
                            <span class="font-mono text-[var(--text-strong)]">{{ $percent }}%</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $percent }}%"></div>
                        </div>
                        <p class="mt-2 text-xs text-[var(--text-muted)]">
                            {{ \App\Support\Quantity::trim($progress['accepted']) }} accepted of
                            {{ \App\Support\Quantity::trim($progress['ordered']) }} ordered,
                            {{ \App\Support\Quantity::trim($progress['outstanding']) }} outstanding.
                        </p>
                    </div>

                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Raised by" :value="$purchaseOrder->creator?->name"/>
                        <x-register.fact label="Submitted">
                            @if ($purchaseOrder->submitted_at)
                                {{ $purchaseOrder->submitter?->name ?? 'Somebody' }}
                                <span class="block text-xs text-[var(--text-muted)]">{{ $purchaseOrder->submitted_at->format('j M Y, H:i') }}</span>
                            @else
                                <span class="text-[var(--text-muted)]">Not yet submitted</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Approval">
                            @if ($purchaseOrder->approved_at)
                                <span class="text-emerald-600">Approved</span> by
                                {{ $purchaseOrder->approver?->name ?? 'somebody' }}
                                <span class="block text-xs text-[var(--text-muted)]">{{ $purchaseOrder->approved_at->format('j M Y, H:i') }}</span>
                            @elseif ($purchaseOrder->rejected_at)
                                <span class="text-rose-600">Rejected</span>
                                <span class="block text-xs text-[var(--text-muted)]">{{ $purchaseOrder->rejected_at->format('j M Y, H:i') }}</span>
                            @else
                                <span class="text-[var(--text-muted)]">Nobody has decided yet</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Issued">
                            @if ($purchaseOrder->issued_at)
                                {{ $purchaseOrder->issuer?->name ?? 'Somebody' }}
                                <span class="block text-xs text-[var(--text-muted)]">{{ $purchaseOrder->issued_at->format('j M Y, H:i') }}</span>
                            @else
                                <span class="text-[var(--text-muted)]">The supplier does not have it yet</span>
                            @endif
                        </x-register.fact>
                        @if ($purchaseOrder->decision_reason)
                            <x-register.fact label="Reason given" :value="$purchaseOrder->decision_reason"/>
                        @endif
                        @if ($purchaseOrder->cancelled_at)
                            <x-register.fact label="Cancelled"
                                             :value="$purchaseOrder->cancellation_reason ?: 'No reason recorded'"/>
                        @endif
                        <x-register.fact label="Confidentiality" :value="$purchaseOrder->confidentiality?->label()"/>
                    </dl>
                </div>

                {{-- Four separate authorities, four separate forms. Submitting is the buyer's act,
                     approving is somebody else's, issuing is the moment the supplier has it, and
                     cancelling an issued order always carries a reason. --}}
                @if ($canSubmit)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('purchase-orders.submit', $purchaseOrder) }}"
                              class="space-y-3">
                            @csrf
                            <p class="text-sm font-medium text-[var(--text-strong)]">Send it for approval</p>
                            <x-input name="comment" placeholder="Anything the approver should know first"/>
                            <button type="submit" class="btn btn-primary btn-sm w-full">Submit for approval</button>
                        </form>
                    </div>
                @endif

                @if ($canApprove || $canReject)
                    <div class="space-y-4 border-t border-[var(--surface-border)] p-5">
                        <p class="text-sm font-medium text-[var(--text-strong)]">Your decision</p>

                        @if ($canApprove)
                            <form method="POST" action="{{ route('purchase-orders.approve', $purchaseOrder) }}"
                                  class="space-y-2">
                                @csrf
                                <x-textarea name="comment" rows="2"
                                            :placeholder="$needsJustification
                                                ? 'Why this order was not competed — required'
                                                : 'Optional note with the approval'"/>
                                <button type="submit" class="btn btn-primary btn-sm w-full">Approve</button>
                            </form>
                        @endif

                        @if ($canReject)
                            <form method="POST" action="{{ route('purchase-orders.reject', $purchaseOrder) }}"
                                  class="space-y-2">
                                @csrf
                                <x-textarea name="comment" rows="2"
                                            placeholder="Why it is being rejected — it goes on the record"/>
                                <button type="submit" class="btn btn-danger btn-sm w-full">Reject</button>
                            </form>
                        @endif

                        <p class="text-xs text-[var(--text-muted)]">
                            Approving is authority to place the order. The supplier still does not
                            have it until it is issued.
                        </p>
                    </div>
                @endif

                @if ($canIssue)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('purchase-orders.issue', $purchaseOrder) }}">
                            @csrf
                            <p class="mb-3 text-sm text-[var(--text-muted)]">
                                Issuing records the moment the supplier has this order and the
                                company is committed. The date is stamped, not typed.
                            </p>
                            <button type="submit" class="btn btn-primary btn-sm w-full">Issue to the supplier</button>
                        </form>
                    </div>
                @endif

                @if ($canCancel && ! $purchaseOrder->cancelled_at)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('purchase-orders.cancel', $purchaseOrder) }}"
                              class="space-y-2">
                            @csrf
                            <p class="text-sm font-medium text-[var(--text-strong)]">Cancel the order</p>
                            <x-textarea name="comment" rows="2"
                                        placeholder="Why — the supplier may already be acting on this"/>
                            <button type="submit" class="btn btn-danger btn-sm w-full">Cancel this order</button>
                        </form>
                    </div>
                @endif

                <x-register.transitions :action="route('purchase-orders.transition', $purchaseOrder)"
                                        :transitions="$transitions" noun="order"/>
            </div>

            <x-timeline :entries="$timeline" title="What has happened"
                        empty="Nothing has happened to this order yet."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            An order somebody outside the company has acted on should be cancelled
                            with a reason, not deleted. Delete only what was raised by mistake.
                        </p>
                        <x-delete-form :action="route('purchase-orders.destroy', $purchaseOrder)"
                                       label="Delete this order"
                                       confirm="This order and its lines will be removed. Continue?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
