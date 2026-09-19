@use('App\Support\Quantity')
{{--
    One quotation, and one evaluator's marks against it.

    THE MARKS PANEL IS PERSONAL. The form is prefilled with the signed-in evaluator's own scores and
    posts nothing that could name anybody else; everybody else's marks appear as a read-only summary
    with their names on. That is not politeness — an evaluation a manager can silently adjust is not
    an evaluation, and QuotationScorePolicy refuses it at the row (§84).

    A BLANK MARK IS AN ANSWER. Leaving a criterion empty records "I cannot judge this", which is
    worth more than a zero that drags the weighted average down as though the supplier had failed.

    THE AWARD IS NOT HERE. Accepting this quotation means marking every other quotation on the
    enquiry unsuccessful in the same transaction, so it happens once, on the enquiry, through
    RfqService::award(). A button here could only ever do half of it.

    THE FILE IS LINKED ONLY TO SOMEBODY WHO COULD OPEN IT ANYWAY. `$canOpenDocument` is a policy
    check on the document itself, not on this record — linking a file to a person who will be
    refused it announces the file rather than sharing it (§67, §68).

    Expects: $quotation, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete,
             $canScore, $canCompare, $canOpenDocument, $criteria, $scoreOutOf, $myScores,
             $canRaiseOrder
--}}
<x-layouts.app :title="$quotation->number">

    <x-page-header
        :title="$quotation->number"
        :description="($quotation->vendor?->name ?: 'Supplier not recorded')
            .($quotation->reference ? ' — their ref '.$quotation->reference : '')">
        <x-slot:actions>
            @if ($canCompare && $quotation->rfq)
                <a href="{{ route('rfqs.comparison', $quotation->rfq) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="sliders" class="h-3.5 w-3.5"/> Compare the enquiry
                </a>
            @endif
            @if ($canRaiseOrder)
                <a href="{{ route('purchase-orders.create', ['quotation' => $quotation->id]) }}"
                   class="btn btn-primary btn-sm">
                    <x-icon name="cart" class="h-3.5 w-3.5"/> Raise the order
                </a>
            @endif
            @if ($canEdit)
                <a href="{{ route('quotations.edit', $quotation) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The quotation</h3>
                    @if ($quotation->hasExpired() && ! $quotation->wasAwarded())
                        <span class="text-xs font-medium text-rose-600">The price no longer holds</span>
                    @elseif ($quotation->expiresWithin(14))
                        <span class="text-xs font-medium text-amber-600">Validity running out</span>
                    @endif
                </div>
                <dl class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-register.fact label="Supplier" :value="$quotation->vendor?->name"/>
                    <x-register.fact label="Their reference" :value="$quotation->reference" mono/>
                    <x-register.fact label="Quoted on" :value="$quotation->quoted_on?->format('j M Y')"/>
                    <x-register.fact label="Holds until" :value="$quotation->valid_until?->format('j M Y')"/>
                    <x-register.fact label="Lead time"
                                     :value="$quotation->lead_time_days !== null ? $quotation->lead_time_days.' days' : null"/>
                    <x-register.fact label="Payment terms" :value="$quotation->payment_terms"/>
                    <x-register.fact label="Delivery terms" :value="$quotation->delivery_terms"/>

                    <div class="sm:col-span-2">
                        <x-register.fact label="Against enquiry">
                            @if ($quotation->rfq)
                                <a href="{{ route('rfqs.show', $quotation->rfq) }}"
                                   class="font-medium hover:text-brand-600">
                                    {{ $quotation->rfq->number }} — {{ $quotation->rfq->title }}
                                </a>
                            @else
                                <span class="text-[var(--text-muted)]">
                                    Unsolicited — nothing was asked for
                                </span>
                            @endif
                        </x-register.fact>
                    </div>

                    @if ($quotation->notes)
                        <div class="sm:col-span-2">
                            <x-register.fact label="Notes" :value="$quotation->notes"/>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What was priced</h3>
                    <span class="text-xs text-[var(--text-muted)]">{{ $quotation->currency }}</span>
                </div>

                @if ($quotation->items->isEmpty())
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            No lines were entered. The total is nought until there are some.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                <tr>
                                    <th class="px-5 py-2 font-medium">#</th>
                                    <th class="px-3 py-2 font-medium">Description</th>
                                    <th class="px-3 py-2 text-right font-medium">Qty</th>
                                    <th class="px-3 py-2 text-right font-medium">Unit price</th>
                                    <th class="px-3 py-2 text-right font-medium">Lead</th>
                                    <th class="px-5 py-2 text-right font-medium">Line total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--surface-border)]">
                                @foreach ($quotation->items as $item)
                                    <tr>
                                        <td class="px-5 py-3 font-mono text-xs text-[var(--text-muted)]">{{ $item->line_no }}</td>
                                        <td class="px-3 py-3">
                                            <span class="text-[var(--text-body)]">{{ $item->description }}</span>
                                            @if ($item->is_alternative)
                                                {{-- An alternative is the supplier answering a
                                                     question that was not asked. It counts towards
                                                     their total, so it is labelled rather than
                                                     hidden — otherwise the cheapest quotation is
                                                     cheapest for something nobody wanted. --}}
                                                <x-badge tone="warning" class="ml-2">Alternative</x-badge>
                                            @endif
                                            @if ($item->notes)
                                                <span class="block text-xs text-[var(--text-muted)]">{{ $item->notes }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-muted)]">
                                            {{ Quantity::withUnit($item->quantity, $item->uom) }}
                                        </td>
                                        <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-body)]">
                                            {{ $item->unit_price !== null ? number_format((float) $item->unit_price, 2) : '—' }}
                                        </td>
                                        <td class="px-3 py-3 text-right font-mono text-xs text-[var(--text-muted)]">
                                            {{ $item->lead_time_days !== null ? $item->lead_time_days.'d' : '—' }}
                                        </td>
                                        <td class="px-5 py-3 text-right font-mono text-sm text-[var(--text-strong)]">
                                            {{ $item->computedTotal() !== null ? number_format((float) $item->computedTotal(), 2) : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-[var(--surface-border)] text-sm">
                                <tr>
                                    <td colspan="5" class="px-5 py-2 text-right text-[var(--text-muted)]">Subtotal</td>
                                    <td class="px-5 py-2 text-right font-mono text-[var(--text-body)]">
                                        {{ number_format((float) $quotation->subtotal, 2) }}
                                    </td>
                                </tr>
                                @if ((float) $quotation->tax_amount > 0)
                                    <tr>
                                        <td colspan="5" class="px-5 py-2 text-right text-[var(--text-muted)]">Tax as quoted</td>
                                        <td class="px-5 py-2 text-right font-mono text-[var(--text-body)]">
                                            {{ number_format((float) $quotation->tax_amount, 2) }}
                                        </td>
                                    </tr>
                                @endif
                                <tr>
                                    <td colspan="5" class="px-5 py-3 text-right font-medium text-[var(--text-strong)]">
                                        Total, {{ $quotation->currency }}
                                    </td>
                                    <td class="px-5 py-3 text-right font-mono font-medium text-[var(--text-strong)]">
                                        {{ number_format((float) $quotation->total, 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>

            @if ($canScore)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Your marks</h3>
                        <span class="text-xs text-[var(--text-muted)]">Out of {{ $scoreOutOf }}</span>
                    </div>

                    <form method="POST" action="{{ route('quotations.scores.store', $quotation) }}">
                        @csrf

                        <div class="space-y-5 p-5">
                            <p class="text-sm text-[var(--text-muted)]">
                                These are yours. Nobody else can change them and you cannot change
                                anybody else's. Leave a criterion blank where you are not the person
                                to judge it — an absence is worth more than a nought.
                            </p>

                            @foreach ($criteria as $key => $label)
                                @php($mine = $myScores[$key] ?? null)
                                <div class="grid gap-3 sm:grid-cols-[10rem_6rem_1fr] sm:items-start">
                                    <label for="score-{{ $key }}" class="label sm:pt-2">{{ $label }}</label>

                                    <input type="number" id="score-{{ $key }}"
                                           name="scores[{{ $key }}][score]" class="input"
                                           step="0.5" min="0" max="{{ $scoreOutOf }}"
                                           value="{{ old('scores.'.$key.'.score', $mine?->score !== null ? rtrim(rtrim(number_format((float) $mine->score, 1, '.', ''), '0'), '.') : '') }}">

                                    <input type="text" name="scores[{{ $key }}][comment]" class="input"
                                           maxlength="2000" placeholder="Why, in a line"
                                           value="{{ old('scores.'.$key.'.comment', $mine?->comment) }}">
                                </div>
                            @endforeach

                            @error('scores')
                                <p class="error">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="border-t border-[var(--surface-border)] px-5 py-4">
                            <button type="submit" class="btn btn-primary btn-sm">Record my marks</button>
                        </div>
                    </form>
                </div>

                @php($others = $quotation->scores->where('evaluated_by', '!=', auth()->id()))

                @if ($others->isNotEmpty())
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">What the other evaluators said</h3>
                        </div>
                        {{-- Read only, and read only for everybody including the buyer running the
                             evaluation. An evaluation a manager can quietly adjust is not one. --}}
                        <ul class="divide-y divide-[var(--surface-border)]">
                            @foreach ($others->groupBy('evaluated_by') as $rows)
                                <li class="p-5">
                                    <p class="text-sm font-medium text-[var(--text-strong)]">
                                        {{ $rows->first()->evaluator?->name ?: 'An evaluator' }}
                                    </p>
                                    <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                        @foreach ($rows as $row)
                                            <div class="text-sm">
                                                <div class="flex items-baseline justify-between gap-3">
                                                    <dt class="text-[var(--text-muted)]">{{ $row->criterion_label }}</dt>
                                                    <dd class="font-mono text-[var(--text-body)]">
                                                        {{ rtrim(rtrim(number_format((float) $row->score, 1, '.', ''), '0'), '.') }}
                                                        <span class="text-xs text-[var(--text-muted)]">/ {{ rtrim(rtrim(number_format((float) $row->max_score, 1, '.', ''), '0'), '.') }}</span>
                                                    </dd>
                                                </div>
                                                @if ($row->comment)
                                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">{{ $row->comment }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </dl>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endif
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Standing</h3>
                </div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="Status">
                        @if ($quotation->status)
                            <x-badge :tone="$quotation->status->tone()">{{ $quotation->status->label }}</x-badge>
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Total"
                                     :value="number_format((float) $quotation->total, 2).' '.$quotation->currency" mono/>

                    @if ($quotation->exchange_rate !== null)
                        {{-- Shown as a fact rather than a headline: the rate was fixed when the
                             quotation was recorded and is never revalued, so this is what the
                             evaluation used and not what the money is worth today. --}}
                        <x-register.fact label="At the rate recorded"
                                         :value="number_format((float) $quotation->totalInBaseCurrency(), 2)" mono/>
                    @endif

                    <x-register.fact label="Evaluation score"
                                     :value="$quotation->evaluationScore() !== null ? $quotation->evaluationScore().' / 10' : null" mono/>

                    <x-register.fact label="Recorded by" :value="$quotation->creator?->name"/>
                    <x-register.fact label="Confidentiality" :value="$quotation->confidentiality?->label()"/>
                </dl>

                <x-register.transitions :action="route('quotations.transition', $quotation)"
                                        :transitions="$transitions" noun="quotation"/>
            </div>

            @if ($quotation->document)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Their document</h3>
                    </div>
                    <div class="p-5">
                        @if ($canOpenDocument)
                            <a href="{{ route('documents.show', $quotation->document) }}"
                               class="text-sm font-medium hover:text-brand-600">
                                {{ $quotation->document->number }} — {{ $quotation->document->title }}
                            </a>
                        @else
                            {{-- Named without being linked would still be telling somebody the file
                                 exists. The record says a file is attached; who may open it is the
                                 document register's business (§67, §68). --}}
                            <p class="text-sm text-[var(--text-muted)]">
                                A file is attached. It is held under a confidentiality your access
                                does not reach.
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            @if ($workflowInstance)
                <x-workflow-progress :instance="$workflowInstance"/>
            @endif

            <x-timeline :entries="$timeline" title="What has happened"
                        empty="Nothing has happened to this quotation yet."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            A quotation that was compared against others is part of the evidence of
                            how the award was made. Delete only what was entered by mistake.
                        </p>
                        <x-delete-form :action="route('quotations.destroy', $quotation)"
                                       label="Delete this quotation"
                                       confirm="This quotation and its lines will be removed. Continue?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$quotation" class="card mt-5"/>

</x-layouts.app>
