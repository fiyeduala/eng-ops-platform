{{--
    Naming a thing so the whole company can agree what it is.

    THE UNIT OF MEASURE IS FREE TEXT ON PURPOSE. Metres, each, litres, rolls, drums, bags, tonnes,
    "100m coil" — a dropdown of units is a dropdown somebody's item is missing from, and the answer
    to a missing unit must never be "call it something else". It is also the one field that becomes
    immutable: once stock has moved in metres, changing the word to rolls would silently re-base
    every balance ever posted, so the service refuses and says so.

    NOT EVERYTHING IN THE CATALOGUE IS STOCKED. "Held in stock" off means the item is bought for a
    job and goes straight there — it still needs a name, a unit and a part number so orders can
    reference it, but no store ever holds a balance of it.

    Expects: $stockItem, $categories
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header"><h3 class="card-title">What it is</h3></div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Code" name="code" required
                             help="Your own scheme. Upper-cased on save.">
                        <x-input name="code" :value="old('code', $stockItem->code)" required
                                 maxlength="60" class="input font-mono uppercase" placeholder="CBL-4C-16"/>
                    </x-field>

                    <x-field label="Name" name="name" required class="sm:col-span-2"
                             help="How a storekeeper would say it out loud.">
                        <x-input name="name" :value="old('name', $stockItem->name)" required
                                 placeholder="4-core 16mm² armoured cable"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Unit of measure" name="uom" required
                             help="Metres, each, litres, rolls — whatever it is actually counted in. It cannot be changed once stock has moved.">
                        <x-input name="uom" :value="old('uom', $stockItem->uom)" required
                                 maxlength="40" placeholder="metres"/>
                    </x-field>

                    <x-field label="Category" name="category"
                             help="Your own list — edit it in Settings.">
                        <x-select name="category" :options="$categories"
                                  :selected="old('category', $stockItem->category)"
                                  placeholder="Not stated"/>
                    </x-field>
                </div>

                <x-field label="Description" name="description"
                         help="What it is, in a sentence somebody unfamiliar would understand.">
                    <x-textarea name="description" :value="old('description', $stockItem->description)" rows="2"/>
                </x-field>

                <x-field label="Specification" name="specification"
                         help="The technical detail: standard, grade, rating. What makes a substitute acceptable or not.">
                    <x-textarea name="specification" :value="old('specification', $stockItem->specification)" rows="3"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Identification</h3></div>
            <div class="grid gap-4 p-5 sm:grid-cols-3">
                <x-field label="Manufacturer" name="manufacturer">
                    <x-input name="manufacturer" :value="old('manufacturer', $stockItem->manufacturer)"/>
                </x-field>

                <x-field label="Part number" name="part_number">
                    <x-input name="part_number" :value="old('part_number', $stockItem->part_number)"
                             class="input font-mono"/>
                </x-field>

                <x-field label="Barcode" name="barcode">
                    <x-input name="barcode" :value="old('barcode', $stockItem->barcode)"
                             class="input font-mono"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">How it is held</h3></div>
            <div class="space-y-5 p-5">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="is_stock_item" value="1" class="mt-0.5"
                           @checked(old('is_stock_item', $stockItem->is_stock_item ?? true))>
                    <span>
                        <span class="font-medium text-[var(--text-strong)]">Held in stock</span>
                        <span class="block text-[var(--text-muted)]">
                            Off for something bought straight to the job. It stays orderable and
                            keeps its name and part number; no store ever holds a balance of it.
                        </span>
                    </span>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Reorder level" name="reorder_level"
                             help="Below this, somebody is told.">
                        <x-input type="number" step="0.0001" min="0" name="reorder_level"
                                 :value="old('reorder_level', $stockItem->reorder_level)"/>
                    </x-field>

                    <x-field label="Usual order quantity" name="reorder_quantity"
                             help="What to order when it gets there.">
                        <x-input type="number" step="0.0001" min="0" name="reorder_quantity"
                                 :value="old('reorder_quantity', $stockItem->reorder_quantity)"/>
                    </x-field>
                </div>

                <p class="text-xs text-[var(--text-muted)]">
                    {{-- Said here rather than only in a validation message, because the pairing is
                         the difference between a warning and an instruction. --}}
                    Set both or neither. A level with no quantity is an alarm nobody can act on; a
                    quantity with no level never fires at all.
                </p>

                <x-field label="Storage conditions" name="storage_conditions"
                         help="Dry, upright, away from heat — anything a storekeeper needs to know.">
                    <x-input name="storage_conditions"
                             :value="old('storage_conditions', $stockItem->storage_conditions)"/>
                </x-field>

                <x-field label="Shelf life, in days" name="shelf_life_days"
                         help="Leave blank for anything that does not expire.">
                    <x-input type="number" min="1" name="shelf_life_days"
                             :value="old('shelf_life_days', $stockItem->shelf_life_days)"/>
                </x-field>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="is_hazardous" value="1" class="mt-0.5"
                           @checked(old('is_hazardous', $stockItem->is_hazardous ?? false))>
                    <span>
                        <span class="font-medium text-[var(--text-strong)]">Hazardous</span>
                        <span class="block text-[var(--text-muted)]">
                            Flagged on every page the item appears on, so nobody handles it without
                            being told.
                        </span>
                    </span>
                </label>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Notes</h3></div>
            <div class="space-y-5 p-5">
                <x-field label="Internal notes" name="notes">
                    <x-textarea name="notes" :value="old('notes', $stockItem->notes)" rows="3"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Catalogue entries are internal by default.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $stockItem->confidentiality?->value)" required/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="stock_item" :record="$stockItem" class="card mt-5"/>
