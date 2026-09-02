@use('App\Support\Quantity')
{{--
    One purchase request — and the first link of the chain, shown as a chain (§90).

    THE BOTTOM OF THIS PAGE IS THE WHOLE POINT. Enquiries raised from this request, and orders
    raised from it, are listed here with their own statuses. A requisition system that cannot answer
    "what happened to my request" is a form, not a system; the answer lives in other registers and
    this page goes and fetches it.

    APPROVE AND REJECT ARE NOT STATUS CHIPS. They are their own forms with their own endpoints,
    because a decision carries a comment and rejection demands a reason. The generic transition
    strip below them handles the moves that are not decisions — cancelling, returning to the
    requester — and the service refuses a decision by the person who submitted it whatever this page
    renders (§84).

    `ordered` IS NEVER A BUTTON. It is set by PurchaseOrderService when an order is raised against
    this request. Nothing on this page can set it, which is why it does not appear among the moves.

    THE TOTAL SAYS ESTIMATED, EVERYWHERE. It is the sum of the lines as the requester guessed them.
    What was committed is on the order, and the two are allowed to differ — that difference is
    information, and hiding it behind one word called "value" throws it away.

    Expects: $purchaseRequest, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete,
             $canSubmit, $canApprove, $canReject, $canRaiseEnquiry
--}}
<x-layouts.app :title="$purchaseRequest->number">

    <x-page-header :title="$purchaseRequest->title"
                   :description="$purchaseRequest->number.($purchaseRequest->project ? ' · '.$purchaseRequest->project->name : '')">
        <x-slot:actions>
            @if ($canRaiseEnquiry)
                <a href="{{ route('rfqs.create', ['from' => $purchaseRequest->id]) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="mail" class="h-3.5 w-3.5"/> Raise an enquiry
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('purchase-requests.edit', $purchaseRequest) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('comment') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('status_id') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($purchaseRequest->required_by && $purchaseRequest->required_by->isPast() && ! $purchaseRequest->status?->is_terminal)
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm text-rose-800 dark:text-rose-200">
                Needed by {{ $purchaseRequest->required_by->format('j M Y') }}, which has passed.
                Either the date has moved or somebody on site is waiting — both are worth saying out
                loud before this is approved.
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
                    <h3 class="card-title">The request</h3>
                    @if ($purchaseRequest->priority)
                        <span class="text-xs text-[var(--text-muted)]">{{ $purchaseRequest->priority }}</span>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Project">
                            @if ($purchaseRequest->project)
                                <a href="{{ route('projects.show', $purchaseRequest->project) }}"
                                   class="hover:text-brand-600">{{ $purchaseRequest->project->name }}</a>
                            @else
                                <span class="text-[var(--text-muted)]">Not project-specific</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Raised by" :value="$purchaseRequest->orgUnit?->name"/>
                        <x-register.fact label="Deliver to" :value="$purchaseRequest->location?->name"/>
                        <x-register.fact label="Needed by"
                                         :value="$purchaseRequest->required_by?->format('j M Y')"/>
                    </dl>

                    @if ($purchaseRequest->justification)
                        <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Justification</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $purchaseRequest->justification }}</p>
                        </div>
                    @endif

                    @if ($purchaseRequest->notes)
                        <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Internal notes</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $purchaseRequest->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Lines</h3>
                    <span class="text-xs text-[var(--text-muted)]">
                        {{ $purchaseRequest->items->count() }} {{ Str::plural('line', $purchaseRequest->items->count()) }}
                    </span>
                </div>

                @if ($purchaseRequest->items->isEmpty())
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        Nothing has been listed yet. A request with no lines cannot be quoted for.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-[var(--surface-muted)] text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium">#</th>
                                    <th class="px-4 py-2 text-left font-medium">Description</th>
                                    <th class="px-4 py-2 text-right font-medium">Quantity</th>
                                    <th class="px-4 py-2 text-right font-medium">Est. rate</th>
                                    <th class="px-4 py-2 text-right font-medium">Est. total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--surface-border)]">
                                @foreach ($purchaseRequest->items as $item)
                                    <tr>
                                        <td class="px-4 py-3 text-xs text-[var(--text-muted)]">{{ $item->line_no }}</td>
                                        <td class="px-4 py-3">
                                            <span class="text-[var(--text-body)]">{{ $item->description }}</span>
                                            @if ($item->specification)
                                                <span class="block text-xs text-[var(--text-muted)]">{{ $item->specification }}</span>
                                            @endif
                                            @if ($item->required_by)
                                                <span class="block text-xs text-[var(--text-muted)]">
                                                    Needed by {{ $item->required_by->format('j M Y') }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono">
                                            {{ Quantity::withUnit($item->quantity, $item->uom) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-[var(--text-muted)]">
                                            {{ $item->estimated_unit_price !== null ? number_format((float) $item->estimated_unit_price, 2) : '—' }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono">
                                            {{ $item->computedTotal() !== null ? number_format((float) $item->computedTotal(), 2) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-[var(--surface-muted)]">
                                <tr>
                                    <td colspan="4" class="px-4 py-2 text-right text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                        Estimated total{{ $purchaseRequest->currency ? ' ('.$purchaseRequest->currency.')' : '' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right font-mono font-medium text-[var(--text-strong)]">
                                        {{ number_format((float) $purchaseRequest->computedTotal(), 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                        An estimate, from whoever raised the request. What it actually costs is
                        settled by the quotations, and the two are allowed to differ.
                    </p>
                @endif
            </div>

            {{-- The chain, read forwards. Only records the reader's own access already reached are
                 in these collections — they came through the same scoped relations everything else
                 does, so this list cannot become a way to learn that an order exists (§61). --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What came of it</h3>
                </div>

                <div class="divide-y divide-[var(--surface-border)]">
                    <div class="p-5">
                        <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Enquiries</p>
                        @if ($purchaseRequest->rfqs->isEmpty())
                            <p class="mt-1 text-sm text-[var(--text-muted)]">
                                No enquiry has been raised from this request yet.
                            </p>
                        @else
                            <ul class="mt-2 space-y-2">
                                @foreach ($purchaseRequest->rfqs as $rfq)
                                    <li class="flex items-center justify-between gap-3">
                                        <a href="{{ route('rfqs.show', $rfq) }}"
                                           class="text-sm hover:text-brand-600">
                                            <span class="font-mono text-xs">{{ $rfq->number }}</span>
                                            — {{ $rfq->title }}
                                        </a>
                                        @if ($rfq->status)
                                            <x-badge :tone="$rfq->status->tone()">{{ $rfq->status->label }}</x-badge>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="p-5">
                        <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Orders</p>
                        @if ($purchaseRequest->purchaseOrders->isEmpty())
                            <p class="mt-1 text-sm text-[var(--text-muted)]">
                                Nothing has been ordered against this request.
                            </p>
                        @else
                            <ul class="mt-2 space-y-2">
                                @foreach ($purchaseRequest->purchaseOrders as $order)
                                    <li class="flex items-center justify-between gap-3">
                                        <a href="{{ route('purchase-orders.show', $order) }}"
                                           class="text-sm hover:text-brand-600">
                                            <span class="font-mono text-xs">{{ $order->number }}</span>
                                            — {{ $order->vendor?->name ?? 'Supplier' }}
                                        </a>
                                        @if ($order->status)
                                            <x-badge :tone="$order->status->tone()">{{ $order->status->label }}</x-badge>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Standing</h3>
                    @if ($purchaseRequest->status)
                        <x-badge :tone="$purchaseRequest->status->tone()">{{ $purchaseRequest->status->label }}</x-badge>
                    @else
                        <x-badge tone="neutral">Draft</x-badge>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Raised by" :value="$purchaseRequest->creator?->name"/>
                        <x-register.fact label="Submitted">
                            @if ($purchaseRequest->submitted_at)
                                {{ $purchaseRequest->submitter?->name ?? 'Somebody' }}
                                <span class="block text-xs text-[var(--text-muted)]">
                                    {{ $purchaseRequest->submitted_at->format('j M Y, H:i') }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">Not yet submitted</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Decision">
                            @if ($purchaseRequest->approved_at)
                                <span class="text-emerald-600">Approved</span> by
                                {{ $purchaseRequest->approver?->name ?? 'somebody' }}
                                <span class="block text-xs text-[var(--text-muted)]">
                                    {{ $purchaseRequest->approved_at->format('j M Y, H:i') }}
                                </span>
                            @elseif ($purchaseRequest->rejected_at)
                                <span class="text-rose-600">Rejected</span> by
                                {{ $purchaseRequest->rejecter?->name ?? 'somebody' }}
                                <span class="block text-xs text-[var(--text-muted)]">
                                    {{ $purchaseRequest->rejected_at->format('j M Y, H:i') }}
                                </span>
                            @else
                                <span class="text-[var(--text-muted)]">Nobody has decided yet</span>
                            @endif
                        </x-register.fact>
                        @if ($purchaseRequest->decision_reason)
                            <x-register.fact label="Reason given" :value="$purchaseRequest->decision_reason"/>
                        @endif
                        <x-register.fact label="Confidentiality"
                                         :value="$purchaseRequest->confidentiality?->label()"/>
                    </dl>
                </div>

                {{-- Submitting is the requester's act; deciding is somebody else's. They are drawn
                     as separate forms rather than as chips because each carries a comment, and
                     because the service refuses a decision taken by the person who submitted it
                     regardless of which buttons this page chose to draw. --}}
                @if ($canSubmit)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('purchase-requests.submit', $purchaseRequest) }}"
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
                            <form method="POST" action="{{ route('purchase-requests.approve', $purchaseRequest) }}"
                                  class="space-y-2">
                                @csrf
                                <x-input name="comment" placeholder="Optional note with the approval"/>
                                <button type="submit" class="btn btn-primary btn-sm w-full">Approve</button>
                            </form>
                        @endif

                        @if ($canReject)
                            <form method="POST" action="{{ route('purchase-requests.reject', $purchaseRequest) }}"
                                  class="space-y-2">
                                @csrf
                                <x-textarea name="comment" rows="2"
                                            placeholder="Why it is being rejected — required, and it goes on the record"/>
                                <button type="submit" class="btn btn-danger btn-sm w-full">Reject</button>
                            </form>
                        @endif

                        <p class="text-xs text-[var(--text-muted)]">
                            Approving is not the same as ordering. The request becomes “ordered” by
                            itself when a purchase order is raised against it.
                        </p>
                    </div>
                @endif

                <x-register.transitions :action="route('purchase-requests.transition', $purchaseRequest)"
                                        :transitions="$transitions" noun="request"/>
            </div>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this request was raised."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            A request that was turned down should be rejected, and one that is no
                            longer wanted should be cancelled — both leave a record of the decision.
                            Deleting is for the one somebody raised twice.
                        </p>
                        <x-delete-form :action="route('purchase-requests.destroy', $purchaseRequest)"
                                       label="Delete this request"
                                       :confirm="'Delete '.$purchaseRequest->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
