{{--
    One rule in the company's pay structure.

    THIS FORM IS THE §47 ARGUMENT MADE VISIBLE. There is no country on it, no "choose your tax
    regime", and no preset table of anybody's income tax. An administrator in Lagos types PAYE,
    pension and NHF; an administrator in Aberdeen types PAYE, National Insurance and a student loan.
    Four fields — kind, method, basis, rate — describe all of them, and the calculator cannot tell
    the two companies apart.

    THE BANDS TABLE IS ALPINE-DRIVEN because a progressive table has an unknown number of rows, and
    a company should be able to add the one its tax authority introduced this year without waiting
    for a release.
--}}
<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Code" name="code" required help="Short and stable — PAYE, PENSION, NHF. It appears on every payslip line calculated from this rule.">
        <x-input name="code" :value="old('code', $payComponent->code)" maxlength="40" required/>
    </x-field>

    <x-field label="Name" name="name" required help="What it is called on the payslip, in the words your people will recognise.">
        <x-input name="name" :value="old('name', $payComponent->name)" required/>
    </x-field>

    <x-field label="Kind" name="kind" required
             help="What it does to the payslip. A relief is not a deduction: it lets part of the pay escape tax without any money moving.">
        <x-select name="kind" :options="$kinds" :selected="old('kind', $payComponent->kind)"/>
    </x-field>

    <x-field label="Method" name="method" required help="How the figure is arrived at.">
        <x-select name="method" :options="$methods" :selected="old('method', $payComponent->method)"/>
    </x-field>

    <x-field label="Calculated on" name="basis" required
             help="What the method is applied to. Resolved when this rule is reached in sequence order — which is how a deduction earlier in the list can reduce the taxable figure a later one reads.">
        <x-select name="basis" :options="$bases" :selected="old('basis', $payComponent->basis)"/>
    </x-field>

    <x-field label="Sequence" name="sequence" required
             help="The order of calculation, and it is your company's policy rather than ours. Whether pension comes out before or after tax is expressed by nothing else on this page.">
        <x-input type="number" name="sequence" :value="old('sequence', $payComponent->sequence)" min="0" max="65535" required/>
    </x-field>

    <x-field label="Rate" name="rate" help="A percentage for a percent component, an amount per hour for an hourly one. Ignored for a fixed amount.">
        <x-input type="number" step="0.0001" name="rate" :value="old('rate', $payComponent->rate)" min="0"/>
    </x-field>

    <x-field label="Fixed amount" name="amount" help="For a flat figure that does not depend on anything — a uniform allowance, a union subscription.">
        <x-input type="number" step="0.01" name="amount" :value="old('amount', $payComponent->amount)" min="0"/>
    </x-field>

    <x-field label="Minimum" name="minimum_amount" help="A floor on the result. Leave blank for none.">
        <x-input type="number" step="0.01" name="minimum_amount" :value="old('minimum_amount', $payComponent->minimum_amount)" min="0"/>
    </x-field>

    <x-field label="Maximum" name="maximum_amount" help="A cap on the result — the ceiling many pension and social security contributions have. Leave blank for none.">
        <x-input type="number" step="0.01" name="maximum_amount" :value="old('maximum_amount', $payComponent->maximum_amount)" min="0"/>
    </x-field>
</div>

<x-field label="Description" name="description" class="mt-4"
         help="Why this exists and where the rate came from. The next administrator will thank you — a rate with no source is a rate nobody dares change.">
    <x-textarea name="description" rows="2">{{ old('description', $payComponent->description) }}</x-textarea>
</x-field>

<h2 class="mt-8 mb-3 text-sm font-semibold text-[var(--text-strong)]">How it behaves</h2>

<div class="grid gap-4 md:grid-cols-2">
    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="is_taxable" value="1" class="mt-0.5" @checked(old('is_taxable', $payComponent->is_taxable ?? true))>
        <span>
            <span class="font-medium text-[var(--text-strong)]">Taxable</span>
            <span class="block text-[var(--text-muted)]">Counts toward the taxable figure that later components read.</span>
        </span>
    </label>

    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="is_pensionable" value="1" class="mt-0.5" @checked(old('is_pensionable', $payComponent->is_pensionable ?? false))>
        <span>
            <span class="font-medium text-[var(--text-strong)]">Pensionable</span>
            <span class="block text-[var(--text-muted)]">Counts toward the pensionable figure. Which parts of pay are pensionable is a company agreement, not a rule of ours.</span>
        </span>
    </label>

    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="reduces_taxable" value="1" class="mt-0.5" @checked(old('reduces_taxable', $payComponent->reduces_taxable ?? false))>
        <span>
            <span class="font-medium text-[var(--text-strong)]">Reduces taxable pay</span>
            <span class="block text-[var(--text-muted)]">For a deduction taken before tax — most pension schemes, and any relief.</span>
        </span>
    </label>

    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="is_prorated" value="1" class="mt-0.5" @checked(old('is_prorated', $payComponent->is_prorated ?? true))>
        <span>
            <span class="font-medium text-[var(--text-strong)]">Pro rata for part periods</span>
            <span class="block text-[var(--text-muted)]">Scaled down for a starter or a leaver. Turn it off for something charged in full whatever the dates — a fixed monthly loan repayment, say.</span>
        </span>
    </label>

    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="applies_to_all" value="1" class="mt-0.5" @checked(old('applies_to_all', $payComponent->applies_to_all ?? false))>
        <span>
            <span class="font-medium text-[var(--text-strong)]">Applies to everybody</span>
            <span class="block text-[var(--text-muted)]">Statutory items usually do. Anything else has to be given to a person individually on their pay page.</span>
        </span>
    </label>

    <label class="flex items-start gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" class="mt-0.5" @checked(old('is_active', $payComponent->is_active ?? true))>
        <span>
            <span class="font-medium text-[var(--text-strong)]">Active</span>
            <span class="block text-[var(--text-muted)]">Turn this off to retire a rule instead of deleting it. Payslips already calculated under it are unaffected either way.</span>
        </span>
    </label>
</div>

{{-- ---------------------------------------------------------------- bands --}}
@php
    $existingBands = old('bands', $payComponent->exists
        ? $payComponent->bands->map(fn ($band) => [
            'from_amount' => (string) $band->from_amount,
            'to_amount' => $band->to_amount === null ? '' : (string) $band->to_amount,
            'rate' => (string) $band->rate,
        ])->values()->all()
        : []);
@endphp

<div class="mt-8" x-data="{ bands: @js(array_values($existingBands ?: [])) }">
    <h2 class="mb-1 text-sm font-semibold text-[var(--text-strong)]">Progressive bands</h2>
    <p class="mb-3 text-xs text-[var(--text-muted)]">
        Only used when the method is “Progressive bands”. Each band's rate applies to the part of the basis
        that falls inside it, not to the whole figure. Leave the top band's upper limit blank to mean “and everything above”.
    </p>

    <template x-if="bands.length">
        <table class="table">
            <thead><tr><th>From</th><th>To</th><th>Rate %</th><th></th></tr></thead>
            <tbody>
                <template x-for="(band, index) in bands" :key="index">
                    <tr>
                        <td><input type="number" step="0.01" min="0" class="input" :name="`bands[${index}][from_amount]`" x-model="band.from_amount"></td>
                        <td><input type="number" step="0.01" min="0" class="input" :name="`bands[${index}][to_amount]`" x-model="band.to_amount" placeholder="and above"></td>
                        <td><input type="number" step="0.0001" min="0" class="input" :name="`bands[${index}][rate]`" x-model="band.rate"></td>
                        <td class="text-right">
                            <button type="button" class="btn btn-ghost btn-sm" @click="bands.splice(index, 1)">Remove</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </template>

    <button type="button" class="btn btn-secondary btn-sm mt-3"
            @click="bands.push({ from_amount: '', to_amount: '', rate: '' })">Add a band</button>
</div>
