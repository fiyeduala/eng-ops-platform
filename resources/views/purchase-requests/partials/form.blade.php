{{--
    Raising a requisition, or correcting one.

    THERE IS NO TOTAL BOX ON THIS FORM. `estimated_total` is a column on the table and a figure on
    the page, and it is arithmetic — quantity times estimated rate, summed — done by the service on
    save. A typed total is a number nobody can reconcile against the lines beneath it, and the two
    disagreeing is the commonest complaint anybody has about a requisition system (§84).

    THE JUSTIFICATION IS THE POINT OF THE RECORD. Approvers do not approve line items, they approve
    a reason; a requisition with a good line list and an empty justification is a form somebody
    filled in rather than a request somebody made. It is not mandatory — plenty of requests are
    obviously routine — but it sits second for a reason.

    STATUS IS ABSENT. A request is raised in draft and moves through submitted, approved and ordered
    from its own page, each move behind its own permission. Nobody types a status.

    Expects: $purchaseRequest, $projects, $units, $people, $confidentialities, $locations,
             $priorities
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What is needed</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required
                         help="What somebody would call it in a corridor, not the full description.">
                    <x-input name="title" :value="old('title', $purchaseRequest->title)" required
                             placeholder="Pipe supports and fixings — Area 3"/>
                </x-field>

                <x-field label="Justification" name="justification"
                         help="Why it is needed, and what happens if it is not bought. This is what the approver is actually approving.">
                    <x-textarea name="justification" :value="old('justification', $purchaseRequest->justification)"
                                rows="4"
                                placeholder="Existing supports are corroded beyond repair; the line cannot be reinstated without them and the shutdown window closes on the 12th."/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Priority" name="priority" help="Your own list — edit it in Settings.">
                        <x-select name="priority" :options="$priorities"
                                  :selected="old('priority', $purchaseRequest->priority)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Needed by" name="required_by"
                             help="The date the goods have to be on site, not the date you would like the order raised.">
                        <x-input type="date" name="required_by"
                                 :value="old('required_by', $purchaseRequest->required_by?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Deliver to" name="location_id"
                             help="Where it is going. Sets the default for the order and the delivery.">
                        <x-select name="location_id" :options="$locations"
                                  :selected="old('location_id', $purchaseRequest->location_id)"
                                  placeholder="Not stated"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The lines</h3>
                <span class="text-xs text-[var(--text-muted)]">Estimates, not prices</span>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-sm text-[var(--text-muted)]">
                    A rate here is what you think it costs, and it stays labelled an estimate the
                    whole way through — the enquiry is what finds out. Leave it blank if you do not
                    know; an invented figure is worse than an absent one, because somebody will
                    budget against it.
                </p>

                @php
                    /*
                     * THE CATALOGUE COLUMN IS ADDED, NOT ALWAYS PRESENT. A person who cannot read
                     * the stock catalogue gets the form exactly as it has always been — seven
                     * free-text columns and no picker — because a column of empty selects would be
                     * a worse form and a list they may not see would be a leak (§61).
                     *
                     * It goes FIRST because it is the shortcut: choose the item and the description
                     * and unit follow from it; type past it and the line is free text, which is
                     * what most lines are.
                     */
                    $lineColumns = [
                        ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => 'w-[26%]', 'placeholder' => 'Galvanised U-bolt, M16'],
                        ['key' => 'specification', 'label' => 'Specification', 'type' => 'textarea', 'width' => 'w-[20%]', 'placeholder' => 'Standard, grade, drawing ref'],
                        ['key' => 'quantity', 'label' => 'Qty', 'type' => 'number', 'step' => 'any', 'min' => '0', 'width' => 'w-24'],
                        ['key' => 'uom', 'label' => 'Unit', 'type' => 'text', 'width' => 'w-20', 'placeholder' => 'no.'],
                        ['key' => 'estimated_unit_price', 'label' => 'Est. rate', 'type' => 'number', 'step' => '0.01', 'min' => '0', 'width' => 'w-28'],
                        ['key' => 'required_by', 'label' => 'Needed by', 'type' => 'date', 'width' => 'w-36'],
                        ['key' => 'notes', 'label' => 'Note', 'type' => 'text', 'width' => 'w-[14%]'],
                    ];

                    if (! empty($stockItems ?? [])) {
                        array_unshift($lineColumns, [
                            'key' => 'stock_item_id',
                            'label' => 'Stock item',
                            'type' => 'select',
                            'options' => $stockItems,
                            'width' => 'w-[18%]',
                            'placeholder' => 'Not a stock item',
                        ]);
                    }
                @endphp

                <x-line-items
                    :rows="$purchaseRequest->items ?? []"
                    quantity-key="quantity"
                    rate-key="estimated_unit_price"
                    :currency="old('currency', $purchaseRequest->currency)"
                    add-label="Add a line"
                    :columns="$lineColumns"/>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Notes</h3>
            </div>
            <div class="p-5">
                <x-field label="Internal notes" name="notes"
                         help="For your colleagues. Suppliers never see this.">
                    <x-textarea name="notes" :value="old('notes', $purchaseRequest->notes)" rows="3"/>
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
                <x-field label="Project" name="project_id"
                         help="Leave blank for a request that is not against a project — office supplies, workshop consumables.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $purchaseRequest->project_id)"
                              placeholder="Not project-specific"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id"
                         help="Who is asking. This decides which approvers see it and who can read it.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $purchaseRequest->org_unit_id)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Currency" name="currency"
                         help="Leave blank to use your organization's own. Fill it in only if the estimate genuinely is in another one.">
                    <x-input name="currency" :value="old('currency', $purchaseRequest->currency)"
                             maxlength="3" class="input font-mono uppercase" placeholder="NGN"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Requests are internal by default. Raise it only where the content warrants it — a confidential request is one fewer person who can approve it.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $purchaseRequest->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="purchase_request" :record="$purchaseRequest" class="card mt-5"/>
