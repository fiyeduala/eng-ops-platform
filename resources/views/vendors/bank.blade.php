{{--
    Where this supplier's money goes.

    ITS OWN PAGE, ITS OWN PERMISSION, ITS OWN AUDIT ENTRY — and the whole reason is one sentence:
    invoice-redirection fraud is the most common way an engineering company loses six figures in an
    afternoon, and it almost never involves breaking in. It involves an email that looks like it came
    from the supplier, a helpful person who updates a field, and a payment run three days later.

    Every control on this screen is aimed at that sequence rather than at an attacker:

      · `vendor.bank` guards the GET as well as the PUT. Serving this form pre-filled to somebody who
        may not read the details would hand over exactly what the permission protects (§67).
      · The REASON is required, and it goes onto the timeline. A change nobody can account for is the
        one that turns out to have come from a forged email.
      · The activity entry names the FIELDS THAT CHANGED and never their values, so the timeline is
        safe to show anybody who can see the vendor. The before/after values live in the audit trail,
        which has its own access rules (§59, §68).
      · No format validation beyond length. An IBAN, a US routing-and-account pair, a Nigerian NUBAN
        and an Indian IFSC have nothing structural in common, and a regex that assumes one of them
        locks out every company that uses another (§47's reasoning, applied to banking).

    Expects: $vendor
--}}
<x-layouts.app :title="'Bank details — '.$vendor->name">

    <x-page-header
        :title="'Bank details'"
        :description="$vendor->name.' · '.$vendor->number"/>

    <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
        <p class="text-sm text-amber-800 dark:text-amber-200">
            <strong>Confirm this out of band before you save.</strong> Telephone the supplier on a
            number you already held — not one from the email or letter asking for the change. Changed
            bank details are the single most common way money is stolen from a company like yours,
            and the request always looks legitimate.
        </p>
    </div>

    <form method="POST" action="{{ route('vendors.bank.update', $vendor) }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">The account</h3>
                    </div>
                    <div class="space-y-5 p-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Bank" name="bank_name">
                                <x-input name="bank_name" :value="old('bank_name', $vendor->bank_name)"/>
                            </x-field>

                            <x-field label="Branch" name="bank_branch">
                                <x-input name="bank_branch" :value="old('bank_branch', $vendor->bank_branch)"/>
                            </x-field>
                        </div>

                        <x-field label="Account name" name="bank_account_name"
                                 help="As the bank holds it. A mismatch between this and the supplier's registered name is the first thing to question.">
                            <x-input name="bank_account_name"
                                     :value="old('bank_account_name', $vendor->bank_account_name)"/>
                        </x-field>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-field label="Account number" name="bank_account_number"
                                     help="Whatever your banking system uses — account number, IBAN, NUBAN. No format is assumed.">
                                <x-input name="bank_account_number" autocomplete="off"
                                         :value="old('bank_account_number', $vendor->bank_account_number)"/>
                            </x-field>

                            <x-field label="SWIFT / sort / routing" name="bank_swift">
                                <x-input name="bank_swift" autocomplete="off"
                                         :value="old('bank_swift', $vendor->bank_swift)"/>
                            </x-field>
                        </div>

                        <p class="text-sm text-[var(--text-muted)]">
                            Clearing every field is a legitimate thing to do. If you suspect the
                            details on file are wrong, empty them — an empty record stops a payment,
                            a wrong one completes it.
                        </p>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Why</h3>
                    </div>
                    <div class="space-y-5 p-5">
                        <x-field label="Reason for the change" name="reason" required
                                 help="And how you confirmed it. This goes onto the vendor's timeline permanently.">
                            <x-textarea name="reason" rows="4" required
                                        placeholder="New account advised by their finance director; confirmed by telephone on the number held since 2023.">{{ old('reason') }}</x-textarea>
                        </x-field>

                        <p class="text-xs text-[var(--text-muted)]">
                            The timeline will record that the bank details changed and which fields
                            moved. It will not record the values themselves — those are in the audit
                            trail, where they are read by the people entitled to read them.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">Save the bank details</button>
            <a href="{{ route('vendors.show', $vendor) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
