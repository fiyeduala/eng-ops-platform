{{--
    The company profile.

    There is no {organization} in the URL — the record comes from the bound tenant context — so
    there is nothing here to renumber. `slug` and `is_active` are absent on purpose: the slug
    identifies the tenant in storage paths and generated numbers, and a tenant that could
    reactivate itself would make suspension meaningless.
--}}
<x-layouts.app title="Company profile">

    <x-page-header
        title="Company profile"
        description="How this company identifies itself. These details appear on generated documents, reports and correspondence.">
        @if (! $canEdit)
            <x-slot:actions>
                <x-badge tone="neutral"><x-icon name="lock" class="h-3 w-3"/> Read only</x-badge>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form method="POST" action="{{ route('organization.update') }}" class="max-w-4xl">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $canEdit) class="space-y-6">

            {{-- ------------------------------------------------------------ identity --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Identity</h3>
                </div>

                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <x-field label="Trading name" name="name" required class="sm:col-span-2"
                             help="What people call this company. Used throughout the interface.">
                        <x-input name="name" :value="$organization->name" required/>
                    </x-field>

                    <x-field label="Registered legal name" name="legal_name" class="sm:col-span-2"
                             help="Used on contracts and formal documents when it differs from the trading name.">
                        <x-input name="legal_name" :value="$organization->legal_name"/>
                    </x-field>

                    <x-field label="Industry" name="industry">
                        <x-input name="industry" :value="$organization->industry" placeholder="Engineering &amp; construction"/>
                    </x-field>

                    <x-field label="Registration number" name="registration_no">
                        <x-input name="registration_no" :value="$organization->registration_no"/>
                    </x-field>

                    <x-field label="Tax identification number" name="tax_id"
                             help="Recorded for reference only. No tax rules are derived from it.">
                        <x-input name="tax_id" :value="$organization->tax_id"/>
                    </x-field>
                </div>
            </div>

            {{-- ------------------------------------------------------------ contact --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Contact</h3>
                </div>

                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <x-field label="General email" name="email">
                        <x-input name="email" type="email" :value="$organization->email"/>
                    </x-field>

                    <x-field label="Telephone" name="phone">
                        <x-input name="phone" type="tel" :value="$organization->phone"/>
                    </x-field>

                    <x-field label="Website" name="website" class="sm:col-span-2"
                             help="Include the scheme, e.g. https://example.com">
                        <x-input name="website" type="url" :value="$organization->website"/>
                    </x-field>

                    <x-field label="Registered address" name="address" class="sm:col-span-2">
                        <x-textarea name="address" :value="$organization->address" rows="3"/>
                    </x-field>

                    <x-field label="City" name="city">
                        <x-input name="city" :value="$organization->city"/>
                    </x-field>

                    <x-field label="State or province" name="state">
                        <x-input name="state" :value="$organization->state"/>
                    </x-field>
                </div>
            </div>

            {{-- ------------------------------------------------------------ regional --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Regional defaults</h3>
                </div>

                <div class="grid gap-5 p-5 sm:grid-cols-2">
                    <x-field label="Country" name="country"
                             help="Two-letter ISO code, e.g. NG, GB, AE. Recorded, never interpreted — no tax or holiday rules are attached to it.">
                        <x-input name="country" :value="$organization->country" maxlength="2" class="uppercase"/>
                    </x-field>

                    <x-field label="Currency" name="currency"
                             help="Three-letter ISO code, e.g. NGN, USD, GBP. The default for new financial records.">
                        <x-input name="currency" :value="$organization->currency" maxlength="3" class="uppercase"/>
                    </x-field>

                    <x-field label="Default time zone" name="timezone" class="sm:col-span-2"
                             help="Used for anyone who has not chosen their own.">
                        <x-select name="timezone"
                                  :selected="$organization->timezone"
                                  placeholder="Not set"
                                  :options="collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz])->all()"/>
                    </x-field>
                </div>
            </div>
        </fieldset>

        @if ($canEdit)
            <div class="mt-5 flex items-center justify-end gap-3">
                <a href="{{ route('dashboard') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save company profile</button>
            </div>
        @else
            <p class="mt-5 text-sm text-[var(--text-muted)]">
                You can see the company profile but not change it. Changing it needs the
                <span class="font-medium text-[var(--text-strong)]">organization.configure</span> permission.
            </p>
        @endif
    </form>

</x-layouts.app>
