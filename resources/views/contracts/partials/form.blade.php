{{--
    Registering a contract, or correcting one.

    THE PARTY PICKER IS THE ONLY CLEVER THING ON THIS PAGE, and it is clever only in the browser.
    Direction and counterparty are one decision made twice — you cannot have a client-side contract
    with a subcontractor — so Alpine keeps `party_type` in step with `direction` and shows whichever
    of the two lists applies. THE UNUSED SELECT IS DISABLED RATHER THAN HIDDEN, because a hidden
    select still posts its value and would send a vendor id under a client-side contract.

    NONE OF THAT IS A CONTROL. Anybody can post whatever they like to this endpoint, so the rule is
    enforced twice more where it counts: ContractRequest::withValidator() rejects a direction and a
    party_type that disagree, and ContractService::attachParty() resolves the id through a
    tenant-scoped query against a two-entry whitelist — never Relation::getMorphedModel(), which
    would cheerfully accept `user` (§75, §84).

    THE PARTY NAME IS KEPT EVEN WHEN THE RECORD IS LINKED. A subcontractor deleted from the register
    three years from now should not turn every historical contract into "with —"; the contract says
    what the paper said.

    RETENTION IS A PERCENTAGE HERE AND A BALANCE ELSEWHERE. This form records what was agreed;
    `retention_held` — actual money — moves on the contract's own page behind `contract.manage`,
    because a figure the commercial reports depend on should not be a text box on a maintenance form.

    Expects: $contract, $projects, $units, $people, $confidentialities, $types, $clients, $vendors,
             $documents
--}}
@php
    $direction = old('direction', $contract->direction ?? 'client');
    $partyId = old('party_id', $contract->party_id);
@endphp

<div class="grid gap-6 lg:grid-cols-3" x-data="{ direction: @js($direction) }">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The agreement</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required
                         help="How people will refer to it in a meeting, not the full legal heading.">
                    <x-input name="title" :value="old('title', $contract->title)" required
                             placeholder="Mechanical installation subcontract — Phase 2"/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Direction" name="direction" required
                             help="Which way the money runs.">
                        <select name="direction" id="direction" class="input" x-model="direction" required>
                            <option value="client">With a client — income</option>
                            <option value="vendor">With a vendor — spend</option>
                        </select>
                    </x-field>

                    <x-field label="Type" name="contract_type"
                             help="Edit the list in Settings — lump sum, remeasurable, framework, whatever your business uses.">
                        <x-select name="contract_type" :options="$types"
                                  :selected="old('contract_type', $contract->contract_type)"
                                  placeholder="Not stated"/>
                    </x-field>

                    <x-field label="Their reference" name="reference"
                             help="The number THEY will quote when they call. Yours is generated.">
                        <x-input name="reference" :value="old('reference', $contract->reference)"/>
                    </x-field>
                </div>

                {{-- party_type is never typed by anybody; it follows the direction above. The
                     service whitelists it again regardless. --}}
                <input type="hidden" name="party_type" x-bind:value="direction">

                <div x-show="direction === 'client'" x-cloak>
                    <x-field label="Client" name="party_id"
                             help="Only clients you can reach appear here. Leave blank and type the name below if they are not on the register yet.">
                        <select name="party_id" class="input" x-bind:disabled="direction !== 'client'">
                            <option value="">Not linked to a client record</option>
                            @foreach ($clients as $id => $label)
                                <option value="{{ $id }}"
                                    @selected($direction === 'client' && (int) $partyId === (int) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <div x-show="direction === 'vendor'" x-cloak>
                    <x-field label="Vendor" name="party_id"
                             help="Only vendors you can reach appear here.">
                        <select name="party_id" class="input" x-bind:disabled="direction !== 'vendor'">
                            <option value="">Not linked to a vendor record</option>
                            @foreach ($vendors as $id => $label)
                                <option value="{{ $id }}"
                                    @selected($direction === 'vendor' && (int) $partyId === (int) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>

                <x-field label="Party name on the contract" name="party_name"
                         help="As written on the paper. Kept even when the record above is linked, so history survives a register tidy-up.">
                    <x-input name="party_name" :value="old('party_name', $contract->party_name)"/>
                </x-field>

                <x-field label="Description" name="description">
                    <x-textarea name="description" rows="3">{{ old('description', $contract->description) }}</x-textarea>
                </x-field>

                <x-field label="Scope summary" name="scope_summary"
                         help="What is actually included. The one paragraph people argue about eighteen months later.">
                    <x-textarea name="scope_summary" rows="4">{{ old('scope_summary', $contract->scope_summary) }}</x-textarea>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Money</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    {{-- No currency symbol beside the field. §47: the organization's own books say
                         what the figure is in, and a hard-coded symbol is wrong for most of the
                         world. The code below is only for the contracts written in another. --}}
                    <x-field label="Contract value" name="value">
                        <x-input type="number" step="0.01" min="0" name="value"
                                 :value="old('value', $contract->value)"/>
                    </x-field>

                    <x-field label="Currency" name="currency"
                             help="Three letters. Leave blank when it is in your own currency.">
                        <x-input name="currency" maxlength="3" class="uppercase"
                                 :value="old('currency', $contract->currency)" placeholder="e.g. USD"/>
                    </x-field>

                    <x-field label="Advance" name="advance_percent" help="Percent paid up front.">
                        <x-input type="number" step="0.01" min="0" max="100" name="advance_percent"
                                 :value="old('advance_percent', $contract->advance_percent)"/>
                    </x-field>
                </div>

                <x-field label="Retention" name="retention_percent"
                         help="The percentage agreed. What has actually been held back is a balance, moved on the contract's own page.">
                    <x-input type="number" step="0.01" min="0" max="50" name="retention_percent"
                             :value="old('retention_percent', $contract->retention_percent)"/>
                </x-field>

                <x-field label="Payment terms" name="payment_terms"
                         help="In words, as the contract states them — milestones, valuation dates, certification periods.">
                    <x-textarea name="payment_terms" rows="3">{{ old('payment_terms', $contract->payment_terms) }}</x-textarea>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Dates</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Signed on" name="signed_on">
                        <x-input type="date" name="signed_on"
                                 :value="old('signed_on', $contract->signed_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Starts" name="starts_on">
                        <x-input type="date" name="starts_on"
                                 :value="old('starts_on', $contract->starts_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Ends" name="ends_on"
                             help="Blank means open-ended. Anything else is watched, and warned on well before the day.">
                        <x-input type="date" name="ends_on"
                                 :value="old('ends_on', $contract->ends_on?->format('Y-m-d'))"/>
                    </x-field>
                </div>

                <x-field label="Defects liability" name="defects_liability_months"
                         help="Months after the end date. This is the period people forget, and the one retention is usually released at the end of.">
                    <x-input type="number" min="0" max="120" name="defects_liability_months"
                             :value="old('defects_liability_months', $contract->defects_liability_months)"/>
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
                <x-field label="Project" name="project_id"
                         help="Leave blank for a framework agreement that spans several jobs.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $contract->project_id)" placeholder="Not project-specific"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $contract->org_unit_id)" placeholder="Company-wide"/>
                </x-field>

                <x-field label="Contract owner" name="owner_id"
                         help="Who answers questions about it. They reach the contract through this.">
                    <x-select name="owner_id" :options="$people"
                              :selected="old('owner_id', $contract->owner_id)" placeholder="Nobody named"/>
                </x-field>

                {{-- Contracts default to confidential. The field stays editable because a framework
                     agreement everybody orders against is a genuinely internal document, but the
                     default is the restrictive one. --}}
                <x-field label="Confidentiality" name="confidentiality" required
                         help="Contracts start confidential. Widening one is a decision worth making on purpose.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $contract->confidentiality?->value)" required/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The signed copy</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Document" name="document_id"
                         help="Only documents you can open are listed, and the link on the contract page is drawn only for readers who can open it too.">
                    <x-select name="document_id" :options="$documents"
                              :selected="old('document_id', $contract->document_id)" placeholder="Not attached"/>
                </x-field>

                <p class="text-xs text-[var(--text-muted)]">
                    The file itself lives in the document register, under its own access rules and
                    its own revision history. Pointing at it from here does not copy it.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Notes</h3>
            </div>
            <div class="p-5">
                <x-field label="Internal notes" name="notes"
                         help="For your own people. Nothing here goes anywhere near the counterparty.">
                    <x-textarea name="notes" rows="5">{{ old('notes', $contract->notes) }}</x-textarea>
                </x-field>
            </div>
        </div>
    </div>
</div>
