{{--
    Asking the store for something, or correcting the ask.

    EVERY LINE MUST NAME A CATALOGUE ITEM. That is the one place this form is stricter than a
    requisition, and the reason is the ledger: a store request is a claim about a shelf, and a shelf
    holds items, not descriptions. "The blue ones" goes in the description beside the item, because
    that is what will actually be said at the counter.

    THE UNIT IS NOT TYPED. It is the catalogue's, copied onto the line by the service at save so the
    record still reads correctly years later if the catalogue is edited. A free-text unit here would
    let somebody ask for 4 boxes of something the store counts in metres.

    THERE IS NO APPROVED QUANTITY ON THIS FORM. What was asked for and what was allowed are
    different facts recorded by different people; the approver sets the second on the request's own
    page, and mixing them here would let a requester approve their own request by typing in the
    wrong box (§84).

    THE STORE IS OPTIONAL. Plenty of sites have one store and nobody thinks about it; where there
    are three, naming the wrong one is worse than naming none, so it is offered rather than demanded
    and the issue itself records which store the stock actually left.

    Expects: $materialRequest, $projects, $units, $people, $confidentialities, $warehouses, $items,
             $locations, $priorities
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">What is needed</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required
                         help="What somebody would call it at the counter.">
                    <x-input name="title" :value="old('title', $materialRequest->title)" required
                             placeholder="Consumables for the Area 3 tie-in"/>
                </x-field>

                <x-field label="What it is for" name="purpose"
                         help="The activity the material is going to. It is what the approver is weighing, and what the storekeeper reads before releasing anything.">
                    <x-textarea name="purpose" :value="old('purpose', $materialRequest->purpose)" rows="4"
                                placeholder="Welding and fit-up of the 12in tie-in spools, shift starting Monday."/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Priority" name="priority" help="Your own list — edit it in Settings.">
                        <x-select name="priority" :options="$priorities"
                                  :selected="old('priority', $materialRequest->priority)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Needed by" name="required_by"
                             help="When it has to be in the hands doing the work.">
                        <x-input type="date" name="required_by"
                                 :value="old('required_by', $materialRequest->required_by?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Collect from" name="warehouse_id"
                             help="Leave blank if it does not matter which store.">
                        <x-select name="warehouse_id" :options="$warehouses"
                                  :selected="old('warehouse_id', $materialRequest->warehouse_id)"
                                  placeholder="Any store"/>
                    </x-field>
                </div>

                <x-field label="Deliver to" name="location_id"
                         help="Where it is going once it leaves the store.">
                    <x-select name="location_id" :options="$locations"
                              :selected="old('location_id', $materialRequest->location_id)"
                              placeholder="Not stated"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The lines</h3>
                <span class="text-xs text-[var(--text-muted)]">Catalogue items only</span>
            </div>
            <div class="space-y-4 p-5">
                <p class="text-sm text-[var(--text-muted)]">
                    Only items the stores actually hold are listed. If what you need is not here it
                    is not stocked, and it has to be bought — raise a purchase request instead.
                </p>

                <x-line-items
                    :rows="$materialRequest->items ?? []"
                    quantity-key="quantity_requested"
                    add-label="Add a line"
                    :columns="[
                        ['key' => 'stock_item_id', 'label' => 'Item', 'type' => 'select', 'options' => $items, 'width' => 'w-[38%]'],
                        ['key' => 'description', 'label' => 'In your words', 'type' => 'text', 'width' => 'w-[22%]', 'placeholder' => 'The blue ones, 300mm'],
                        ['key' => 'quantity_requested', 'label' => 'Qty', 'type' => 'number', 'step' => 'any', 'min' => '0', 'width' => 'w-24'],
                        ['key' => 'required_by', 'label' => 'Needed by', 'type' => 'date', 'width' => 'w-36'],
                        ['key' => 'notes', 'label' => 'Note', 'type' => 'text', 'width' => 'w-[16%]'],
                    ]"/>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Notes</h3>
            </div>
            <div class="p-5">
                <x-field label="Internal notes" name="notes"
                         help="Anything the store should know that is not part of the request itself.">
                    <x-textarea name="notes" :value="old('notes', $materialRequest->notes)" rows="3"/>
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
                {{-- The project is what the issue will be charged to. Blank is honest for a yard
                     draw that belongs to no job, and better than the first name in the list. --}}
                <x-field label="Project" name="project_id"
                         help="What the material is being drawn for. The issue is charged here.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $materialRequest->project_id)"
                              placeholder="Not project-specific"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id"
                         help="Who is asking. This decides which approvers see it and who can read it.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $materialRequest->org_unit_id)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Store requests are internal by default. Raising it means fewer people can approve or issue it.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $materialRequest->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
