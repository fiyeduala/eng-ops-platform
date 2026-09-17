<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Code" name="code" required><x-input name="code" :value="old('code', $account->code)" placeholder="PC-SITE-01"/></x-field>
    <x-field label="Name" name="name" required><x-input name="name" :value="old('name', $account->name)" placeholder="Site office cash tin"/></x-field>
    <x-field label="Custodian" name="custodian_id" help="The person who holds the cash."><x-select name="custodian_id" :options="$people" :selected="old('custodian_id', $account->custodian_id)" placeholder="No named custodian"/></x-field>
    <x-field label="Float" name="float_amount" help="The most the tin is topped up to. Zero means no ceiling."><x-input type="number" step="0.01" min="0" name="float_amount" :value="old('float_amount', $account->float_amount ?? 0)"/></x-field>
    <x-field label="Organization unit" name="org_unit_id"><x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $account->org_unit_id)" placeholder="Organization-wide"/></x-field>
    <x-field label="Project" name="project_id"><x-select name="project_id" :options="$projects" :selected="old('project_id', $account->project_id)" placeholder="No project"/></x-field>
    <x-field label="Confidentiality" name="confidentiality" required><x-select name="confidentiality" :options="$confidentialities" :selected="old('confidentiality', $account->confidentiality?->value ?? $account->confidentiality)"/></x-field>
    <label class="flex items-center gap-2 pt-7"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))> Active</label>
</div>
<x-field label="Notes" name="notes" class="mt-4"><x-textarea name="notes" rows="2">{{ old('notes', $account->notes) }}</x-textarea></x-field>
