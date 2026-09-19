<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Title" name="title" required class="md:col-span-2"><x-input name="title" :value="old('title', $income->title)" placeholder="What the client is being billed for"/></x-field>
    <x-field label="Category" name="category" required><x-select name="category" :options="$categories" :selected="old('category', $income->category)" placeholder="Choose a category"/></x-field>
    <x-field label="Invoice reference" name="invoice_reference"><x-input name="invoice_reference" :value="old('invoice_reference', $income->invoice_reference)"/></x-field>
    <x-field label="Date" name="income_date" required><x-input type="date" name="income_date" :value="old('income_date', $income->income_date?->format('Y-m-d') ?? $income->income_date)"/></x-field>
    <x-field label="Due" name="due_on"><x-input type="date" name="due_on" :value="old('due_on', $income->due_on?->format('Y-m-d'))"/></x-field>
    <x-field label="Net amount" name="amount" required help="Before tax."><x-input type="number" step="0.01" min="0.01" name="amount" :value="old('amount', $income->amount)"/></x-field>
    <x-field label="Tax code" name="tax_code_id"><x-select name="tax_code_id" :options="$taxCodes" :selected="old('tax_code_id', $income->tax_code_id)" placeholder="No tax"/></x-field>
    <x-field label="Contract" name="contract_id" help="A client contract fills in the client and project."><x-select name="contract_id" :options="$contracts" :selected="old('contract_id', $income->contract_id)" placeholder="No contract"/></x-field>
    <x-field label="Client" name="client_id"><x-select name="client_id" :options="$clients" :selected="old('client_id', $income->client_id)" placeholder="No client"/></x-field>
    <x-field label="Project" name="project_id"><x-select name="project_id" :options="$projects" :selected="old('project_id', $income->project_id)" placeholder="No project"/></x-field>
    <x-field label="Organization unit" name="org_unit_id"><x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $income->org_unit_id)" placeholder="No unit"/></x-field>
    <x-field label="Confidentiality" name="confidentiality" required><x-select name="confidentiality" :options="$confidentialities" :selected="old('confidentiality', $income->confidentiality?->value ?? $income->confidentiality)"/></x-field>
</div>
<x-field label="Description" name="description" class="mt-4"><x-textarea name="description" rows="3">{{ old('description', $income->description) }}</x-textarea></x-field>
<x-field label="Notes" name="notes" class="mt-4"><x-textarea name="notes" rows="2">{{ old('notes', $income->notes) }}</x-textarea></x-field>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="income" :record="$income" class="card mt-5"/>
