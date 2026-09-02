{{--
    Raising an enquiry, or correcting one.

    THE INVITATION LIST IS NOT ON THIS FORM. Suppliers are invited one at a time from the enquiry's
    own page, each invitation stamped with when it was sent, because who was asked and when is
    evidence — of competition, and of fairness to the suppliers who were not asked. A multi-select
    that could be edited afterwards would make that evidence worthless (§59).

    THE CLOSING DATE IS A REAL DEADLINE, so a new enquiry must close in the future. Editing one is
    allowed to leave the date where it is — enquiries are corrected after they close all the time,
    and refusing to save a typo because the deadline has passed helps nobody.

    RAISED FROM A REQUISITION, THE LINES COME ACROSS. `$source` is the approved requisition this
    enquiry was started from; it was resolved through the buyer's own scoped query, so it is here
    only if they could open it anyway (§84).

    Expects: $rfq, $source, $projects, $units, $people, $confidentialities, $purchaseRequests
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What you are asking for</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required
                         help="What the suppliers will see in the subject line.">
                    <x-input name="title" :value="old('title', $rfq->title)" required
                             placeholder="Supply of pipe supports and fixings — Area 3"/>
                </x-field>

                <x-field label="Scope" name="scope"
                         help="What is being asked for, in the words you want quoted against.">
                    <x-textarea name="scope" :value="old('scope', $rfq->scope)" rows="4"
                                placeholder="Supply and delivery to site of the items listed, to the standards noted. Delivery to the tank farm gate, offloading by others."/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Closes at" name="closes_at"
                             help="After this the enquiry is closed for responses. A quotation received later is recorded late rather than refused, and the timeline says so.">
                        <x-input type="datetime-local" name="closes_at"
                                 :value="old('closes_at', $rfq->closes_at?->format('Y-m-d\TH:i'))"/>
                    </x-field>

                    <x-field label="Against requisition" name="purchase_request_id"
                             help="Approved requisitions only. This is the link that lets the chain be followed end to end.">
                        <x-select name="purchase_request_id" :options="$purchaseRequests"
                                  :selected="old('purchase_request_id', $rfq->purchase_request_id)"
                                  placeholder="Not against a requisition"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The lines</h3>
                <span class="text-xs text-[var(--text-muted)]">No prices — that is what you are asking for</span>
            </div>
            <div class="space-y-4 p-5">
                @if ($source)
                    {{-- Raised from a requisition, the lines are COPIED BY THE SERVICE and whatever
                         this form posted is ignored. Showing an editable grid that gets overwritten
                         would be a lie told politely, so the lines are shown as they will arrive and
                         the enquiry is edited afterwards like any other. --}}
                    <div class="rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] p-4">
                        <p class="text-sm text-[var(--text-body)]">
                            These lines come across from
                            <a href="{{ route('purchase-requests.show', $source) }}"
                               class="font-medium hover:text-brand-600">{{ $source->number }}</a>,
                            each one keeping its link back to the requisition line it came from. Save
                            the enquiry first, then edit the lines here if the wording needs work.
                        </p>
                    </div>

                    <ul class="divide-y divide-[var(--surface-border)] text-sm">
                        @foreach ($source->items as $item)
                            <li class="flex items-start justify-between gap-4 py-2">
                                <span class="text-[var(--text-body)]">
                                    {{ $item->description }}
                                    @if ($item->specification)
                                        <span class="block text-xs text-[var(--text-muted)]">{{ $item->specification }}</span>
                                    @endif
                                </span>
                                <span class="whitespace-nowrap font-mono text-xs text-[var(--text-muted)]">
                                    {{ \App\Support\Quantity::withUnit($item->quantity, $item->uom) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-[var(--text-muted)]">
                        Describe what you want quoted for. Quantities matter even when they are
                        approximate — a supplier pricing “some fixings” is pricing a guess.
                    </p>

                    <x-line-items
                        :rows="$rfq->items ?? []"
                        add-label="Add a line"
                        :columns="[
                            ['key' => 'purchase_request_item_id', 'label' => '', 'type' => 'hidden'],
                            ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => 'w-[34%]', 'placeholder' => 'Galvanised U-bolt, M16'],
                            ['key' => 'specification', 'label' => 'Specification', 'type' => 'textarea', 'width' => 'w-[28%]', 'placeholder' => 'Standard, grade, drawing ref'],
                            ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number', 'step' => 'any', 'min' => '0', 'width' => 'w-24'],
                            ['key' => 'uom', 'label' => 'Unit', 'type' => 'text', 'width' => 'w-20', 'placeholder' => 'no.'],
                            ['key' => 'notes', 'label' => 'Note', 'type' => 'text', 'width' => 'w-[18%]'],
                        ]"/>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Terms and notes</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Terms sent with the enquiry" name="terms"
                         help="Payment terms, delivery basis, validity you expect quotations to hold. Suppliers see this.">
                    <x-textarea name="terms" :value="old('terms', $rfq->terms)" rows="3"
                                placeholder="Quotations to remain valid for 60 days. Delivered to site, duty paid. Payment 30 days from receipt of invoice."/>
                </x-field>

                <x-field label="Internal notes" name="notes"
                         help="For your colleagues. Suppliers never see this.">
                    <x-textarea name="notes" :value="old('notes', $rfq->notes)" rows="3"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it belongs</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $rfq->project_id)"
                              placeholder="Not project-specific"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id"
                         help="Whose enquiry this is. It decides who can read it.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $rfq->org_unit_id)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="An enquiry is internal by default. The scope goes out to suppliers either way — this controls who inside the company can open the record.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $rfq->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Next</h3>
            </div>
            <div class="p-5">
                <p class="text-sm text-[var(--text-muted)]">
                    Saving this creates a draft. Suppliers are invited from the enquiry's own page,
                    and nothing is sent to anybody until you issue it — at which point the invitation
                    list is fixed as evidence of who was asked.
                </p>
            </div>
        </div>
    </div>
</div>
