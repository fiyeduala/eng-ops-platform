{{--
    Recording what a supplier sent back.

    THIS FORM IS DATA ENTRY, NOT AUTHORSHIP. Somebody is typing up a PDF that arrived by email. So
    every field is the supplier's word for something — their reference, their price, their lead time
    — and the only judgement on the page is the confidentiality of the record.

    THE TOTAL IS NOT AN INPUT. The service sums the lines and adds the tax figure, so a total that
    disagrees with the lines cannot exist. Where the supplier's own total disagrees with their own
    arithmetic, that belongs in the notes, because it is a conversation to have with them.

    WHO MAY BE PICKED DEPENDS ON THE ENQUIRY. Against an enquiry, only the invited suppliers are
    offered — a quotation from a company that was never asked is a process failure, and the service
    refuses it regardless of what this select shows (§84).

    THE FILE LIST IS THE READER'S OWN. Documents are offered through the person's own scoped query,
    so attaching one is never a way to reach a file they could not open (§67).

    Expects: $quotation, $rfq, $vendors, $rfqs, $documents, $confidentialities
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Who quoted, and against what</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Supplier" name="vendor_id" required
                             help="Against an enquiry, only the suppliers who were invited.">
                        <x-select name="vendor_id" :options="$vendors"
                                  :selected="old('vendor_id', $quotation->vendor_id)"
                                  placeholder="Choose a supplier" required/>
                    </x-field>

                    <x-field label="Against enquiry" name="rfq_id"
                             help="Leave empty for a quotation that arrived without one being asked for.">
                        @if ($rfq && $quotation->exists)
                            {{-- Moving a quotation from one enquiry to another would orphan its
                                 line links and quietly change what it was compared against, so on
                                 an existing record the enquiry is shown rather than offered. --}}
                            <p class="text-sm text-[var(--text-body)]">
                                {{ $rfq->number }} — {{ $rfq->title }}
                            </p>
                            <input type="hidden" name="rfq_id" value="{{ $rfq->id }}">
                        @else
                            <x-select name="rfq_id" :options="$rfqs"
                                      :selected="old('rfq_id', $quotation->rfq_id)"
                                      placeholder="Unsolicited — no enquiry"/>
                        @endif
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Their reference" name="reference"
                             help="What the supplier calls it. Quote it back at them on the telephone.">
                        <x-input name="reference" :value="old('reference', $quotation->reference)"
                                 placeholder="QT-2026-0481"/>
                    </x-field>

                    <x-field label="Quoted on" name="quoted_on">
                        <x-input type="date" name="quoted_on"
                                 :value="old('quoted_on', $quotation->quoted_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Holds until" name="valid_until"
                             help="The date the price stops being theirs to honour.">
                        <x-input type="date" name="valid_until"
                                 :value="old('valid_until', $quotation->valid_until?->format('Y-m-d'))"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What they priced</h3>
                <span class="text-xs text-[var(--text-muted)]">Totals are worked out from the lines</span>
            </div>
            <div class="space-y-4 p-5">
                @if ($rfq && $rfq->items->isNotEmpty() && ! $quotation->exists)
                    <p class="text-sm text-[var(--text-muted)]">
                        The enquiry asked for {{ $rfq->items->count() }}
                        {{ \Illuminate\Support\Str::plural('line', $rfq->items->count()) }}. Price
                        them here, and mark anything the supplier offered instead of what was asked
                        for as an alternative — those are the lines that lose evaluations quietly.
                    </p>
                @endif

                <x-line-items
                    :rows="$quotation->items ?? []"
                    add-label="Add a priced line"
                    quantity-key="quantity"
                    rate-key="unit_price"
                    :currency="old('currency', $quotation->currency)"
                    :columns="[
                        ['key' => 'rfq_item_id', 'label' => '', 'type' => 'hidden'],
                        ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => 'w-[30%]', 'placeholder' => 'As quoted by the supplier'],
                        ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number', 'step' => 'any', 'min' => '0', 'width' => 'w-24'],
                        ['key' => 'uom', 'label' => 'Unit', 'type' => 'text', 'width' => 'w-20', 'placeholder' => 'no.'],
                        ['key' => 'unit_price', 'label' => 'Unit price', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'width' => 'w-32'],
                        ['key' => 'lead_time_days', 'label' => 'Lead (days)', 'type' => 'number', 'step' => '1', 'min' => '0', 'width' => 'w-24'],
                        ['key' => 'is_alternative', 'label' => 'Alt.', 'type' => 'checkbox', 'width' => 'w-16'],
                        ['key' => 'notes', 'label' => 'Note', 'type' => 'text', 'width' => 'w-[16%]'],
                    ]"/>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Terms</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Payment terms" name="payment_terms">
                        <x-input name="payment_terms" :value="old('payment_terms', $quotation->payment_terms)"
                                 placeholder="30 days from invoice"/>
                    </x-field>

                    <x-field label="Delivery terms" name="delivery_terms">
                        <x-input name="delivery_terms" :value="old('delivery_terms', $quotation->delivery_terms)"
                                 placeholder="Delivered to site, offloading by others"/>
                    </x-field>
                </div>

                <x-field label="Notes" name="notes"
                         help="Anything the supplier said that is not a number. Where their own total disagrees with their own lines, say so here.">
                    <x-textarea name="notes" :value="old('notes', $quotation->notes)" rows="3"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Money</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Currency" name="currency"
                         help="Three letters. Leave it as your own currency unless they quoted in another.">
                    <x-input name="currency" :value="old('currency', $quotation->currency)"
                             maxlength="3" placeholder="NGN"/>
                </x-field>

                {{-- Fixed here, once, and never revalued afterwards: the comparison must read the
                     same next year as it did at the evaluation meeting. --}}
                <x-field label="Exchange rate" name="exchange_rate"
                         help="Only when the currency is not your own. The rate is stored on this quotation and never updated, so the comparison stays the one that was made.">
                    <x-input type="number" name="exchange_rate" step="0.000001" min="0"
                             :value="old('exchange_rate', $quotation->exchange_rate)"/>
                </x-field>

                <x-field label="Tax as quoted" name="tax_amount"
                         help="The figure on their document, whatever it is called. Nothing here knows what VAT is.">
                    <x-input type="number" name="tax_amount" step="0.01" min="0"
                             :value="old('tax_amount', $quotation->tax_amount)"/>
                </x-field>

                <x-field label="Lead time (days)" name="lead_time_days"
                         help="For the quotation as a whole. Individual lines can differ.">
                    <x-input type="number" name="lead_time_days" step="1" min="0"
                             :value="old('lead_time_days', $quotation->lead_time_days)"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The document itself</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Their quotation" name="document_id"
                         help="The file they sent. Only files you can already open are offered.">
                    <x-select name="document_id" :options="$documents"
                              :selected="old('document_id', $quotation->document_id)"
                              placeholder="Not attached"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Quotations start confidential. A live tender is the one place where a colleague knowing the other bids invalidates the process rather than merely embarrassing somebody.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $quotation->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="quotation" :record="$quotation" class="card mt-5"/>
