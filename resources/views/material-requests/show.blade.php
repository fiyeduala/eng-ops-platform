{{--
    One material request, and the three decisions taken against it.

    ONE LINE TABLE, FOUR NUMBERS: asked for, approved, issued, still owed. They are four different
    facts recorded by three different people, and a single "quantity" column would hide which of
    them anybody is looking at. `issued` is not stored anywhere — it is summed from the ledger rows
    pointing at the line, so a reversal posted this afternoon changes this page this afternoon.

    THE AVAILABILITY COLUMN IS NOT ALWAYS DRAWN. It is what the named store is holding right now,
    and it is shown only to somebody who may issue: a requester asking for four bolts does not need
    the store's position to ask, and §68 says not to volunteer it. It is absent, not blanked.

    APPROVING AND ISSUING SHARE A SHAPE AND MEAN DIFFERENT THINGS. Both post a quantity per line;
    one records what was allowed, the other what physically left the shelf. Leaving a line alone
    means "as asked for" when approving — never "none" — which is why the boxes carry the requested
    figure as a placeholder rather than as a value.

    Expects: $materialRequest, $timeline, $transitions, $workflowInstance, $availability,
             $canEdit, $canDelete, $canSubmit, $canApprove, $canReject, $canIssue
--}}
@php
    $lines = $materialRequest->items;
@endphp
<x-layouts.app :title="$materialRequest->number">

    <x-page-header :title="$materialRequest->title"
                   :description="$materialRequest->number.' · raised by '.($materialRequest->creator?->name ?? 'someone no longer here')">
        <x-slot:actions>
            @if ($materialRequest->status)
                <x-badge :tone="$materialRequest->status->tone()">{{ $materialRequest->status->label }}</x-badge>
            @endif

            @if ($canSubmit)
                <form method="POST" action="{{ route('material-requests.submit', $materialRequest) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Send to the store</button>
                </form>
            @endif

            @if ($canEdit)
                <a href="{{ route('material-requests.edit', $materialRequest) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            @if (filled($materialRequest->purpose))
                <div class="card p-5">
                    <h3 class="card-title mb-3">What it is for</h3>
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $materialRequest->purpose }}</p>
                </div>
            @endif

            <div class="card overflow-hidden">
                <div class="card-header">
                    <h3 class="card-title">The lines</h3>
                    <span class="text-xs text-[var(--text-muted)]">
                        {{ $lines->count() }} {{ Str::plural('line', $lines->count()) }}
                    </span>
                </div>

                @if ($lines->isEmpty())
                    <x-empty-state icon="clipboard" title="No lines"
                                   description="Nothing has been listed on this request yet. Edit it to add what the work needs."/>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th class="text-right">Asked for</th>
                                    <th class="text-right">Approved</th>
                                    <th class="text-right">Issued</th>
                                    <th class="text-right">Outstanding</th>
                                    @if ($availability)
                                        <th class="text-right">In store</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($lines as $line)
                                @php
                                    $issued = $line->quantityIssued();
                                    $outstanding = $line->quantityOutstanding();
                                    $onHand = $availability[$line->stock_item_id] ?? null;
                                @endphp
                                <tr>
                                    <td>
                                        <span class="font-medium text-[var(--text-strong)]">
                                            {{ $line->stockItem?->name }}
                                        </span>
                                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                                            {{ $line->stockItem?->code }}
                                        </span>
                                        @if (filled($line->description))
                                            <span class="block text-xs text-[var(--text-muted)]">{{ $line->description }}</span>
                                        @endif
                                    </td>

                                    <td class="text-right font-mono text-sm">
                                        {{ (float) $line->quantity_requested }} {{ $line->uom }}
                                    </td>

                                    {{-- Null is not zero. Nothing has been decided yet, and writing
                                         0 would read as "refused" to the person holding the list. --}}
                                    <td class="text-right font-mono text-sm">
                                        @if ($line->quantity_approved === null)
                                            <span class="text-[var(--text-muted)]">—</span>
                                        @else
                                            <span @class(['text-amber-600' => (float) $line->quantity_approved < (float) $line->quantity_requested])>
                                                {{ (float) $line->quantity_approved }}
                                            </span>
                                        @endif
                                    </td>

                                    <td class="text-right font-mono text-sm text-[var(--text-muted)]">{{ $issued }}</td>

                                    <td class="text-right font-mono text-sm">
                                        <span @class(['font-medium text-emerald-600' => $outstanding <= 0.0])>
                                            {{ $outstanding }}
                                        </span>
                                    </td>

                                    @if ($availability)
                                        <td class="text-right font-mono text-sm">
                                            @if ($onHand === null)
                                                <span class="text-[var(--text-muted)]">none</span>
                                            @else
                                                <span @class(['text-rose-600' => $onHand < $outstanding])>{{ $onHand }}</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            @if ($lines->isNotEmpty() && $materialRequest->isAwaitingDecision() && $canApprove)
                {{-- Approving for less than was asked for is the normal case, not the exception, so
                     it is one form rather than an "approve" button and a hidden "amend" screen. A
                     box left empty means the line is agreed as asked for. --}}
                <div class="card p-5">
                    <h3 class="card-title mb-2">Approve</h3>
                    <p class="mb-4 text-sm text-[var(--text-muted)]">
                        Leave a line blank to agree it as asked for. Type a smaller figure to cut it.
                        Approving reserves the stock; it is released when the request is closed.
                    </p>

                    <form method="POST" action="{{ route('material-requests.approve', $materialRequest) }}"
                          class="space-y-4">
                        @csrf
                        <div class="space-y-2">
                            @foreach ($lines as $line)
                                <div class="flex items-center gap-3">
                                    <span class="flex-1 text-sm text-[var(--text-body)]">
                                        {{ $line->stockItem?->name }}
                                        <span class="text-[var(--text-muted)]">
                                            — asked for {{ (float) $line->quantity_requested }} {{ $line->uom }}
                                        </span>
                                    </span>
                                    <input type="number" step="0.0001" min="0" class="input w-32"
                                           name="quantities[{{ $line->id }}]"
                                           placeholder="{{ (float) $line->quantity_requested }}"
                                           value="{{ old('quantities.'.$line->id) }}">
                                </div>
                            @endforeach
                        </div>

                        <x-field label="Note" name="notes" help="Why anything was cut, if it was.">
                            <x-textarea name="notes" rows="2"/>
                        </x-field>

                        <button type="submit" class="btn btn-primary btn-sm">Approve the request</button>
                    </form>
                </div>
            @endif

            @if ($materialRequest->isAwaitingDecision() && $canReject)
                <div class="card p-5">
                    <h3 class="card-title mb-2">Refuse</h3>
                    <p class="mb-4 text-sm text-[var(--text-muted)]">
                        A refusal ends the request. A reason is required — the person who raised it
                        has to be able to do something about it.
                    </p>

                    <form method="POST" action="{{ route('material-requests.reject', $materialRequest) }}"
                          class="space-y-3">
                        @csrf
                        <x-field label="Reason" name="notes" required>
                            <x-textarea name="notes" rows="2" required/>
                        </x-field>
                        <button type="submit" class="btn btn-danger btn-sm">Refuse the request</button>
                    </form>
                </div>
            @endif

            @if ($canIssue && $lines->isNotEmpty())
                {{-- What actually left the shelf, which is not always what was approved: the store
                     found three of the four, or the site only took two today. Each figure posts a
                     ledger row, and the status is worked out from what was posted rather than
                     clicked by anybody. --}}
                <div class="card p-5">
                    <h3 class="card-title mb-2">Issue from the store</h3>
                    <p class="mb-4 text-sm text-[var(--text-muted)]">
                        Type what is going out now. Blank means nothing on that line today; the
                        request stays open until the balance is handed over or it is closed short.
                    </p>

                    <form method="POST" action="{{ route('material-requests.issue', $materialRequest) }}"
                          class="space-y-4">
                        @csrf
                        <div class="space-y-2">
                            @foreach ($lines as $line)
                                @php $outstanding = $line->quantityOutstanding(); @endphp
                                <div class="flex items-center gap-3">
                                    <span class="flex-1 text-sm text-[var(--text-body)]">
                                        {{ $line->stockItem?->name }}
                                        <span class="text-[var(--text-muted)]">
                                            — {{ $outstanding }} {{ $line->uom }} still owed
                                        </span>
                                    </span>
                                    <input type="number" step="0.0001" min="0" class="input w-32"
                                           name="quantities[{{ $line->id }}]"
                                           placeholder="0"
                                           @disabled($outstanding <= 0.0)
                                           value="{{ old('quantities.'.$line->id) }}">
                                </div>
                            @endforeach
                        </div>

                        <x-field label="Note" name="notes" help="Who collected it, or anything the ledger should carry.">
                            <x-textarea name="notes" rows="2"/>
                        </x-field>

                        <button type="submit" class="btn btn-primary btn-sm">Record the issue</button>
                    </form>
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this request yet."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <h3 class="card-title mb-4">Particulars</h3>
                <dl class="space-y-4">
                    <x-register.fact label="Number" :value="$materialRequest->number" mono/>
                    <x-register.fact label="Store" :value="$materialRequest->warehouse?->name ?: 'Any store'"/>
                    <x-register.fact label="Project" :value="$materialRequest->project?->name"/>
                    <x-register.fact label="Owning unit" :value="$materialRequest->orgUnit?->name"/>
                    <x-register.fact label="Deliver to" :value="$materialRequest->location?->name"/>
                    <x-register.fact label="Priority" :value="$materialRequest->priority"/>
                    <x-register.fact label="Needed by"
                                     :value="$materialRequest->required_by?->format('j M Y')"/>
                    <x-register.fact label="Confidentiality"
                                     :value="$materialRequest->confidentiality?->label()"/>
                </dl>
            </div>

            @if ($materialRequest->submitted_at || $materialRequest->approved_at || $materialRequest->issued_at)
                <div class="card p-5">
                    <h3 class="card-title mb-4">Who decided what</h3>
                    <dl class="space-y-4">
                        <x-register.fact label="Submitted">
                            {{ $materialRequest->submitter?->name ?: '—' }}
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $materialRequest->submitted_at?->format('j M Y, H:i') }}
                            </span>
                        </x-register.fact>

                        <x-register.fact label="Approved">
                            {{ $materialRequest->approver?->name ?: '—' }}
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $materialRequest->approved_at?->format('j M Y, H:i') }}
                            </span>
                        </x-register.fact>

                        <x-register.fact label="First issued">
                            {{ $materialRequest->issuer?->name ?: '—' }}
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $materialRequest->issued_at?->format('j M Y, H:i') }}
                            </span>
                        </x-register.fact>
                    </dl>

                    @if (filled($materialRequest->decision_notes))
                        <p class="mt-4 whitespace-pre-line border-t border-[var(--border)] pt-4 text-sm text-[var(--text-body)]">
                            {{ $materialRequest->decision_notes }}
                        </p>
                    @endif
                </div>
            @endif

            @if ($workflowInstance)
                <x-workflow-progress :instance="$workflowInstance"/>
            @endif

            @if ($transitions->isNotEmpty() || $canDelete)
                <div class="card p-5">
                    <h3 class="card-title mb-4">Move it on</h3>

                    <x-register.transitions :action="route('material-requests.transition', $materialRequest)"
                                            :transitions="$transitions" noun="request"/>

                    @if ($canDelete)
                        <div class="mt-4 border-t border-[var(--border)] pt-4">
                            <x-delete-form :action="route('material-requests.destroy', $materialRequest)"
                                           label="Delete the request"
                                           confirm="This removes the request. Anything already issued against it stays on the ledger. Continue?"/>
                        </div>
                    @endif
                </div>
            @endif

            @if (filled($materialRequest->notes))
                <div class="card p-5">
                    <h3 class="card-title mb-3">Notes</h3>
                    <p class="whitespace-pre-line text-sm text-[var(--text-muted)]">{{ $materialRequest->notes }}</p>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$materialRequest" class="card mt-5"/>

</x-layouts.app>
