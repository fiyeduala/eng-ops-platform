{{--
    One form, five verbs — and the verb is never a field.

    `$verb` ARRIVES FROM THE URL AND IS ONLY EVER USED TO CHOOSE AN ACTION AND A HEADING. There is
    no `type` input anywhere on this page, hidden or otherwise, because a form that posts the kind
    of movement it is making is a form somebody can retype (§84). The controller has already
    demanded the matching permission before rendering, and the endpoint this posts to demands it
    again. Editing the URL does not widen anything; it changes which permission is asked for.

    WHAT DIFFERS BETWEEN THE FIVE is small enough to read in one screen: a transfer needs two
    stores, a count asks what was on the shelf rather than what moved, and only a receipt carries a
    price. Everything else — item, project, reason, when — is the same question five times, so it
    is asked once.

    A COUNT'S FIELD IS `counted_quantity`, NOT A DIFFERENCE. The storekeeper types what they found;
    the service works out the correction and posts nothing at all if the count agreed. Asking for
    the difference would be asking somebody with a clipboard to do the arithmetic the ledger exists
    to do.

    Expects: $verb, $heading, $warehouses, $items, $projects, $units, $canSeeValue, $defaults
--}}
@php
    $action = match ($verb) {
        'receive' => 'stock.receive',
        'issue' => 'stock.issue',
        'return' => 'stock.return',
        'transfer' => 'stock.transfer',
        'count' => 'stock.count',
    };

    $blurb = match ($verb) {
        'receive' => 'Stock arriving into a store. A delivery against a purchase order posts itself — this is for everything else.',
        'issue' => 'Stock leaving a store for the work. The store must hold it, unless it is one of the stores allowed to go negative.',
        'return' => 'Stock coming back unused. It goes in at the store\'s current average cost.',
        'transfer' => 'Stock moving between two stores. Written as two rows sharing one reference, so neither ledger has a gap.',
        'count' => 'What was actually on the shelf. If it agrees with the book, nothing is posted.',
    };
@endphp
<x-layouts.app :title="$heading">

    <x-page-header :title="$heading" :description="$blurb">
        <x-slot:actions>
            <a href="{{ route('stock.index') }}" class="btn btn-ghost btn-sm">Cancel</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route($action) }}" class="grid gap-6 lg:grid-cols-3">
        @csrf

        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h3 class="card-title mb-4">What moved</h3>
                <div class="grid gap-4 sm:grid-cols-2">

                    @if ($verb === 'transfer')
                        <x-field label="From store" name="from_warehouse_id" required
                                 help="The store the stock leaves.">
                            <x-select name="from_warehouse_id" :options="$warehouses"
                                      :selected="old('from_warehouse_id', $defaults['warehouse_id'])"
                                      placeholder="Choose a store" required/>
                        </x-field>

                        <x-field label="To store" name="to_warehouse_id" required
                                 help="Must be a different store.">
                            <x-select name="to_warehouse_id" :options="$warehouses"
                                      :selected="old('to_warehouse_id')"
                                      placeholder="Choose a store" required/>
                        </x-field>
                    @else
                        <x-field label="Store" name="warehouse_id" required
                                 help="Only stores currently accepting movements are listed.">
                            <x-select name="warehouse_id" :options="$warehouses"
                                      :selected="old('warehouse_id', $defaults['warehouse_id'])"
                                      placeholder="Choose a store" required/>
                        </x-field>
                    @endif

                    <x-field label="Item" name="stock_item_id" required
                             help="Catalogue entries marked as stocked. A service or a one-off purchase is not one."
                             class="{{ $verb === 'transfer' ? 'sm:col-span-2' : '' }}">
                        <x-select name="stock_item_id" :options="$items"
                                  :selected="old('stock_item_id', $defaults['stock_item_id'])"
                                  placeholder="Choose an item" required/>
                    </x-field>

                    @if ($verb === 'count')
                        <x-field label="Counted quantity" name="counted_quantity" required
                                 help="What is physically there. The correction is worked out from it.">
                            <x-input name="counted_quantity" type="number" step="0.0001" min="0"
                                     :value="old('counted_quantity')" required/>
                        </x-field>
                    @else
                        <x-field label="Quantity" name="quantity" required
                                 help="Always a positive number. Which way it goes is decided by the movement, not by a sign.">
                            <x-input name="quantity" type="number" step="0.0001" min="0.0001"
                                     :value="old('quantity')" required/>
                        </x-field>
                    @endif

                    @if ($verb === 'receive' && $canSeeValue)
                        {{-- Only a receipt carries a price. An issue is valued at the store's
                             running average, and letting somebody type a cost on the way out is how
                             a stock valuation stops reconciling. --}}
                        <x-field label="Unit cost" name="unit_cost"
                                 help="Leave blank to keep the item's current average cost.">
                            <x-input name="unit_cost" type="number" step="0.0001" min="0"
                                     :value="old('unit_cost')"/>
                        </x-field>
                    @endif

                    @if (in_array($verb, ['receive', 'return'], true))
                        <x-field label="Batch or heat number" name="batch_ref"
                                 help="Traceability reference from the delivery, where the material carries one.">
                            <x-input name="batch_ref" :value="old('batch_ref')"/>
                        </x-field>

                        <x-field label="Expires on" name="expires_on"
                                 help="For material with a shelf life — resins, consumables, calibration gases.">
                            <x-input name="expires_on" type="date" :value="old('expires_on')"/>
                        </x-field>
                    @endif
                </div>
            </div>

            <div class="card p-5">
                <h3 class="card-title mb-4">Why, and when</h3>
                <div class="space-y-4">
                    <x-field label="Reason" name="reason" required
                             help="{{ $verb === 'count'
                                 ? 'What the count was for — a quarterly stocktake, a spot check, an investigation.'
                                 : 'What this movement was for. It is what the next person reading the ledger has to go on.' }}">
                        <x-textarea name="reason" rows="2" :value="old('reason')" required/>
                    </x-field>

                    <x-field label="Notes" name="notes" help="Anything else worth recording.">
                        <x-textarea name="notes" rows="3" :value="old('notes')"/>
                    </x-field>

                    @if ($verb !== 'count')
                        {{-- Stock routinely moves before anybody reaches a keyboard. The ledger
                             records when it happened; `created_at` records when it was typed, and
                             the two are allowed to differ. Future dates are refused. --}}
                        <x-field label="When it happened" name="occurred_at"
                                 help="Leave blank for now. Cannot be in the future.">
                            <x-input name="occurred_at" type="datetime-local" :value="old('occurred_at')"/>
                        </x-field>
                    @endif
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <h3 class="card-title mb-4">Charge it to</h3>
                <div class="space-y-4">
                    {{-- Not required, and deliberately so. A store issue to the yard belongs to no
                         project, and forcing a choice would put it on whichever project was first
                         in the list — which is worse than leaving it unattributed. --}}
                    <x-field label="Project" name="project_id"
                             help="Where the material is going, if it is going to a project.">
                        <x-select name="project_id" :options="$projects" :selected="old('project_id')"
                                  placeholder="No project"/>
                    </x-field>

                    @if ($verb !== 'transfer' && $verb !== 'count')
                        <x-field label="Unit" name="org_unit_id"
                                 help="The team drawing or returning it.">
                            <x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id')"
                                      placeholder="No unit"/>
                        </x-field>
                    @endif
                </div>
            </div>

            <div class="card p-5">
                <button type="submit" class="btn btn-primary w-full">{{ $heading }}</button>
                <p class="mt-3 text-xs text-[var(--text-muted)]">
                    Once posted this cannot be edited or deleted. A mistake is corrected by a
                    reversal, which leaves both rows on the ledger.
                </p>
            </div>
        </div>
    </form>

</x-layouts.app>
