{{--
    Adding a client, or correcting one already on the register.

    THE ACCOUNT OWNER IS NOT ON THIS FORM. It looks like an ordinary select and it is not one:
    `relationship_owner_id` is the column the access engine reads to decide who reaches this client,
    so naming somebody here would be granting them sight of the account. It moves on the client's
    own page, behind `client.manage` (§84).

    NEITHER IS THE STATUS. Approving a client to be invoiced is a transition with a workflow behind
    it, not a dropdown on a maintenance form — the same argument the vendor form makes about
    prequalification.

    Expects: $client, $units, $confidentialities, $types, $industries
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The organization</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Name" name="name" required
                         help="What your people call them. The name on the contract goes below if it differs.">
                    <x-input name="name" :value="old('name', $client->name)" required/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Registered name" name="legal_name">
                        <x-input name="legal_name" :value="old('legal_name', $client->legal_name)"/>
                    </x-field>

                    <x-field label="Your code" name="code"
                             help="Optional. The client code your finance system already uses.">
                        <x-input name="code" :value="old('code', $client->code)"/>
                    </x-field>

                    <x-field label="Tax identifier" name="tax_id">
                        <x-input name="tax_id" :value="old('tax_id', $client->tax_id)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Registration number" name="registration_no">
                        <x-input name="registration_no" :value="old('registration_no', $client->registration_no)"/>
                    </x-field>

                    <x-field label="Type" name="client_type"
                             help="What kind of counterparty they are to you.">
                        <x-select name="client_type" :options="$types"
                                  :selected="old('client_type', $client->client_type)" placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Industry" name="industry"
                             help="Edit this list in Settings — these are your sectors, not ours.">
                        <x-select name="industry" :options="$industries"
                                  :selected="old('industry', $client->industry)" placeholder="Not stated"/>
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
                        <x-input type="email" name="email" :value="old('email', $client->email)"/>
                    </x-field>

                    <x-field label="Phone" name="phone">
                        <x-input name="phone" :value="old('phone', $client->phone)"/>
                    </x-field>

                    <x-field label="Website" name="website">
                        <x-input type="url" name="website" :value="old('website', $client->website)"
                                 placeholder="https://"/>
                    </x-field>
                </div>

                <x-field label="Address" name="address">
                    <x-textarea name="address" rows="2">{{ old('address', $client->address) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="City" name="city">
                        <x-input name="city" :value="old('city', $client->city)"/>
                    </x-field>

                    <x-field label="State or region" name="state">
                        <x-input name="state" :value="old('state', $client->state)"/>
                    </x-field>

                    <x-field label="Country" name="country">
                        <x-input name="country" :value="old('country', $client->country)"/>
                    </x-field>
                </div>

                <p class="text-sm text-[var(--text-muted)]">
                    Named people at this organization are added on the client's own page once it is
                    saved. They are rows against the company, so that a project engineer who leaves
                    can be retired without the correspondence that named them changing.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Commercial terms</h3>
            </div>
            <div class="space-y-5 p-5">
                {{-- Recorded, never enforced. See the Client model: half a credit control system is
                     worse than none, so nothing in the application blocks on these. --}}
                <p class="text-sm text-[var(--text-muted)]">
                    These are recorded for reference. Nothing in the application stops an invoice or
                    a project because of them — credit control is a decision your finance team makes,
                    not one this register makes for them.
                </p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Payment terms" name="payment_terms_days"
                             help="Days from invoice, as agreed with them.">
                        <x-input type="number" min="0" max="365" name="payment_terms_days"
                                 :value="old('payment_terms_days', $client->payment_terms_days)"/>
                    </x-field>

                    <x-field label="Credit limit" name="credit_limit"
                             help="Leave blank when none has been agreed. A zero means something different.">
                        <x-input type="number" step="0.01" min="0" name="credit_limit"
                                 :value="old('credit_limit', $client->credit_limit)"/>
                    </x-field>
                </div>

                <x-field label="Notes" name="notes">
                    <x-textarea name="notes" rows="4">{{ old('notes', $client->notes) }}</x-textarea>
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
                         help="Which part of the business holds this relationship. Leave blank for a client the whole company works for.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $client->org_unit_id)" placeholder="Company-wide"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Most client records are internal. Restricting one hides it from everybody outside the owning unit.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $client->confidentiality?->value)" required/>
                </x-field>

                <label class="flex items-start gap-2 text-sm">
                    <input type="checkbox" name="is_active" value="1" class="mt-0.5"
                           @checked(old('is_active', $client->is_active ?? true))>
                    <span>
                        <span class="font-medium text-[var(--text-strong)]">Active</span>
                        <span class="block text-[var(--text-muted)]">
                            Inactive clients stay readable and drop out of the pickers on new
                            projects and contracts. The work already done for them is untouched.
                        </span>
                    </span>
                </label>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="client" :record="$client" class="card mt-5"/>
