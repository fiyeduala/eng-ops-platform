{{--
    Adding a supplier, or correcting one already on the register.

    THREE THINGS ARE MISSING FROM THIS FORM ON PURPOSE, and each omission is a control rather than an
    oversight:

      · NO BANK DETAILS. They have their own screen, their own permission and their own audit
        narrative. Invoice-redirection fraud works because changing an account number LOOKS like
        ordinary maintenance — so here it is not something that can happen while somebody is fixing
        a phone number (§67, §84).
      · NO BLACKLIST TOGGLE. Barring a company demands a written reason and moves the status with it.
        A checkbox could be quietly unticked by whoever was caught.
      · NO RATING. It is the mean of recorded assessments. Nobody types their friend a four.

    THE PREQUALIFICATION DATES ARE HERE, BUT APPROVAL IS NOT. Recording that an assessment happened
    is clerical work; deciding the supplier is approved is a status move gated on `vendor.manage`.
    A coordinator files the certificate, a manager accepts it.

    Expects: $vendor, $units, $confidentialities, $types, $categories
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The company</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Trading name" name="name" required
                         help="What your people call them. The legal name goes below.">
                    <x-input name="name" :value="old('name', $vendor->name)" required/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Registered name" name="legal_name"
                             help="The name on the contract and the invoice, if it differs.">
                        <x-input name="legal_name" :value="old('legal_name', $vendor->legal_name)"/>
                    </x-field>

                    <x-field label="Trade name" name="trade_name">
                        <x-input name="trade_name" :value="old('trade_name', $vendor->trade_name)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Your code" name="code"
                             help="Optional. Paste the supplier code from an existing system so the two can be reconciled.">
                        <x-input name="code" :value="old('code', $vendor->code)"/>
                    </x-field>

                    <x-field label="Registration number" name="registration_no">
                        <x-input name="registration_no" :value="old('registration_no', $vendor->registration_no)"/>
                    </x-field>

                    <x-field label="Tax identifier" name="tax_id">
                        <x-input name="tax_id" :value="old('tax_id', $vendor->tax_id)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Type" name="vendor_type"
                             help="The kind of relationship, not what they sell.">
                        <x-select name="vendor_type" :options="$types"
                                  :selected="old('vendor_type', $vendor->vendor_type)" placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Category" name="category"
                             help="What they supply. Edit the list in Settings — these are your words, not ours.">
                        <x-select name="category" :options="$categories"
                                  :selected="old('category', $vendor->category)" placeholder="Not categorised"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">How to reach them</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Email" name="email">
                        <x-input type="email" name="email" :value="old('email', $vendor->email)"/>
                    </x-field>

                    <x-field label="Phone" name="phone">
                        <x-input name="phone" :value="old('phone', $vendor->phone)"/>
                    </x-field>

                    <x-field label="Website" name="website">
                        <x-input type="url" name="website" :value="old('website', $vendor->website)"
                                 placeholder="https://"/>
                    </x-field>
                </div>

                <x-field label="Address" name="address">
                    <x-textarea name="address" rows="2">{{ old('address', $vendor->address) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="City" name="city">
                        <x-input name="city" :value="old('city', $vendor->city)"/>
                    </x-field>

                    <x-field label="State or region" name="state">
                        <x-input name="state" :value="old('state', $vendor->state)"/>
                    </x-field>

                    <x-field label="Country" name="country">
                        <x-input name="country" :value="old('country', $vendor->country)"/>
                    </x-field>
                </div>

                <p class="text-sm text-[var(--text-muted)]">
                    Named people at this company are added on the vendor's own page once it is saved —
                    they are rows against the company, not fields on this form.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Commercial terms</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Payment terms" name="payment_terms_days"
                             help="Days from invoice. The date itself is worked out per invoice, so the term is what is held here.">
                        <x-input type="number" min="0" max="365" name="payment_terms_days"
                                 :value="old('payment_terms_days', $vendor->payment_terms_days)"/>
                    </x-field>

                    {{-- No currency symbol beside it. §47 — the company's own books say what the
                         figure is in, and a hard-coded one is wrong for most of the world. --}}
                    <x-field label="Credit limit" name="credit_limit"
                             help="Leave blank when no limit has been agreed. A zero means something different.">
                        <x-input type="number" step="0.01" min="0" name="credit_limit"
                                 :value="old('credit_limit', $vendor->credit_limit)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Prequalified on" name="prequalified_on">
                        <x-input type="date" name="prequalified_on"
                                 :value="old('prequalified_on', $vendor->prequalified_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Prequalification expires" name="prequalification_expires_on"
                             help="The date the register warns on, well before it arrives. Blank means nobody is watching it.">
                        <x-input type="date" name="prequalification_expires_on"
                                 :value="old('prequalification_expires_on', $vendor->prequalification_expires_on?->format('Y-m-d'))"/>
                    </x-field>
                </div>

                <x-field label="Notes" name="notes">
                    <x-textarea name="notes" rows="4">{{ old('notes', $vendor->notes) }}</x-textarea>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it sits</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Owning unit" name="org_unit_id"
                         help="Who maintains this record. Leave blank for a supplier the whole company uses.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $vendor->org_unit_id)" placeholder="Company-wide"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Most supplier records are internal. Restricting one hides it from everybody outside the owning unit.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $vendor->confidentiality?->value)" required/>
                </x-field>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" class="mt-0.5"
                           @checked(old('is_active', $vendor->is_active ?? true))>
                    <span>
                        <span class="font-medium text-[var(--text-strong)]">Active</span>
                        <span class="block text-[var(--text-muted)]">
                            Inactive vendors stay readable and drop out of every picker. Use this for a
                            supplier you have simply stopped using — blacklisting is a different act,
                            with a reason attached.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </div>
</div>
