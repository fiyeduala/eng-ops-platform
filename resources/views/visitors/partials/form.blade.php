{{--
    A booking, not a check-in. Nothing on this form touches the gate: no badge, no induction, no
    identity document, no arrival time. Those belong to the moment somebody actually walks in, are
    posted by a different form under a different permission, and are refused here by both the form
    request and the service (§84).

    Almost everything is optional on purpose. A walk-in gives a name and who they are here to see,
    and that is genuinely all reception gets. A required phone number does not produce phone numbers;
    it produces a column full of 0000000000, which is worse than a blank one because it looks like
    data.
--}}
<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Name" name="full_name" required help="As they give it. This is what appears on the roll call.">
        <x-input name="full_name" :value="old('full_name', $visitor->full_name)" required autofocus/>
    </x-field>

    <x-field label="Company" name="company_name" help="Who they are here on behalf of, if anybody.">
        <x-input name="company_name" :value="old('company_name', $visitor->company_name)"/>
    </x-field>

    <x-field label="Here to see" name="host_employee_id"
             help="The host. They are told when their visitor arrives, and the visit belongs to them. Only people you are entitled to see are listed.">
        <x-select name="host_employee_id" :options="$hosts" :selected="old('host_employee_id', $visitor->host_employee_id)" placeholder="Nobody named"/>
    </x-field>

    <x-field label="How many people" name="party_size" required
             help="A delegation arrives on one record. The roll call counts heads, so this number matters more than it looks.">
        <x-input type="number" name="party_size" min="1" max="200" :value="old('party_size', $visitor->party_size ?? 1)" required/>
    </x-field>

    <x-field label="Reason for the visit" name="purpose_type" help="Your company's list, editable under Settings → Visitors.">
        <x-select name="purpose_type" :options="$purposes" :selected="old('purpose_type', $visitor->purpose_type)" placeholder="Not stated"/>
    </x-field>

    <x-field label="What about" name="purpose" help="One line. Reception reads this out when they ring the host.">
        <x-input name="purpose" :value="old('purpose', $visitor->purpose)"/>
    </x-field>
</div>

<h2 class="mt-8 mb-3 text-sm font-semibold text-[var(--text-strong)]">Where and when</h2>

<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Site" name="location_id" help="Which of your places they are coming to. The roll call is filtered by this.">
        <x-select name="location_id" :options="$locations" :selected="old('location_id', $visitor->location_id)" placeholder="Not stated"/>
    </x-field>

    <x-field label="Department" name="org_unit_id">
        <x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $visitor->org_unit_id)" placeholder="Not stated"/>
    </x-field>

    <x-field label="Project" name="project_id" help="For a visit to a job. It is also what a project-scoped site administrator sees.">
        <x-select name="project_id" :options="$projects" :selected="old('project_id', $visitor->project_id)" placeholder="Not stated"/>
    </x-field>

    <div class="grid gap-4 sm:grid-cols-2">
        <x-field label="Expected from" name="expected_from" help="Leave both blank for a walk-in.">
            <x-input type="datetime-local" name="expected_from"
                     :value="old('expected_from', $visitor->expected_from?->format('Y-m-d\TH:i') ?? $visitor->expected_from)"/>
        </x-field>

        <x-field label="Until" name="expected_to">
            <x-input type="datetime-local" name="expected_to"
                     :value="old('expected_to', $visitor->expected_to?->format('Y-m-d\TH:i'))"/>
        </x-field>
    </div>
</div>

<h2 class="mt-8 mb-3 text-sm font-semibold text-[var(--text-strong)]">Escort</h2>
<p class="mb-3 text-xs text-[var(--text-muted)]">
    Whether contractors must be escorted is a company rule, set under Settings → Visitors. If it is on, a
    contractor visit will require an escort at the gate whatever is ticked here — the desk cannot sign them
    in without one.
</p>

<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Needs an escort" name="requires_escort">
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="requires_escort" value="0">
            <input type="checkbox" name="requires_escort" value="1" class="rounded border-[var(--border)]"
                   @checked(old('requires_escort', $visitor->requires_escort))>
            <span>This visitor must not be left unaccompanied</span>
        </label>
    </x-field>

    <x-field label="Escorted by" name="escort_employee_id" help="Can be named now or at the gate.">
        <x-select name="escort_employee_id" :options="$hosts" :selected="old('escort_employee_id', $visitor->escort_employee_id)" placeholder="Not yet named"/>
    </x-field>
</div>

<h2 class="mt-8 mb-3 text-sm font-semibold text-[var(--text-strong)]">Contact details</h2>
<p class="mb-3 text-xs text-[var(--text-muted)]">Optional, and only worth filling in if the visit needs to be changed or called off.</p>

<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Phone" name="phone">
        <x-input name="phone" :value="old('phone', $visitor->phone)"/>
    </x-field>

    <x-field label="Email" name="email">
        <x-input type="email" name="email" :value="old('email', $visitor->email)"/>
    </x-field>
</div>

<div class="mt-6">
    <x-field label="Notes" name="notes" help="Anything the desk should know. Not a place for personal details about the visitor.">
        <x-textarea name="notes" rows="3">{{ old('notes', $visitor->notes) }}</x-textarea>
    </x-field>
</div>
