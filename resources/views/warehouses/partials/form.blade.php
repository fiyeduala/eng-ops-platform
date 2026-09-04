{{--
    Opening a store, or correcting one.

    THE CODE IS TYPED, NOT GENERATED. A store's identifier is read off a shelf label, a bin card and
    a delivery note by people who never open this system, so it has to be the word they already use
    — MAIN, LAG-YARD, TRANSIT-01. It is upper-cased on save so the same store cannot exist twice in
    two capitalisations.

    STATUS IS ABSENT, and so is any way to close a store from this form. A store is opened,
    suspended and closed from its own page, and closing goes through the service, which refuses
    while anything is still on the shelf (§84).

    Expects: $warehouse, $projects, $units, $keepers, $confidentialities, $locations, $types
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header"><h3 class="card-title">The store</h3></div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Code" name="code" required
                             help="What is written on the shelf labels. Upper-cased on save.">
                        <x-input name="code" :value="old('code', $warehouse->code)" required
                                 maxlength="40" class="input font-mono uppercase" placeholder="LAG-YARD"/>
                    </x-field>

                    <x-field label="Name" name="name" required class="sm:col-span-2"
                             help="What people call it when they are standing in front of it.">
                        <x-input name="name" :value="old('name', $warehouse->name)" required
                                 placeholder="Lagos yard — main store"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Kind of store" name="type"
                             help="Your own list — edit it in Settings. A transit store is a lorry; a quarantine store holds what has not passed inspection.">
                        <x-select name="type" :options="$types" :selected="old('type', $warehouse->type)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Where it is" name="location_id"
                             help="Leave blank for a store that moves.">
                        <x-select name="location_id" :options="$locations"
                                  :selected="old('location_id', $warehouse->location_id)"
                                  placeholder="Not stated"/>
                    </x-field>
                </div>

                <x-field label="Storekeeper" name="keeper_id"
                         help="Who answers for what is in it. Recorded, not enforced — the permission to issue is granted through roles.">
                    <x-select name="keeper_id" :options="$keepers"
                              :selected="old('keeper_id', $warehouse->keeper_id)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Notes" name="notes"
                         help="Access arrangements, opening hours, who holds the key.">
                    <x-textarea name="notes" :value="old('notes', $warehouse->notes)" rows="3"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Where it belongs</h3></div>
            <div class="space-y-5 p-5">
                <x-field label="Project" name="project_id"
                         help="A site store belongs to its project and is read by that project's people. Leave blank for a central store.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $warehouse->project_id)"
                              placeholder="Not project-specific"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id"
                         help="Who runs it. This decides who can read the store and its balances.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $warehouse->org_unit_id)"
                              placeholder="Not stated"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Stores are internal by default. Restricting one hides its balances from everybody outside the owning unit.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $warehouse->confidentiality?->value)" required/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Issuing rule</h3></div>
            <div class="p-5">
                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="allow_negative_stock" value="1" class="mt-0.5"
                           @checked(old('allow_negative_stock', $warehouse->allow_negative_stock ?? false))>
                    <span>
                        <span class="font-medium text-[var(--text-strong)]">Allow the balance to go below zero</span>
                        <span class="block text-[var(--text-muted)]">
                            Off for nearly every store. A negative balance is almost always a receipt
                            somebody forgot to enter rather than a fact about the world, and refusing
                            the issue is what makes them enter it. Turn it on for a transit store,
                            where goods legitimately leave before the paperwork arrives.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </div>
</div>
