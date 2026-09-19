<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Title" name="title" required class="md:col-span-2"><x-input name="title" :value="old('title', $expense->title)" placeholder="What the money was spent on"/></x-field>
    <x-field label="Category" name="category" required><x-select name="category" :options="$categories" :selected="old('category', $expense->category)" placeholder="Choose a category"/></x-field>
    <x-field label="Date spent" name="expense_date" required><x-input type="date" name="expense_date" :value="old('expense_date', $expense->expense_date?->format('Y-m-d') ?? $expense->expense_date)"/></x-field>
    <x-field label="Net amount" name="amount" required help="Before tax. Tax and the total are worked out from the tax code."><x-input type="number" step="0.01" min="0.01" name="amount" :value="old('amount', $expense->amount)"/></x-field>
    <x-field label="Tax code" name="tax_code_id"><x-select name="tax_code_id" :options="$taxCodes" :selected="old('tax_code_id', $expense->tax_code_id)" placeholder="No tax"/></x-field>
    @if ($vendors !== [])
        <x-field label="Vendor" name="vendor_id"><x-select name="vendor_id" :options="$vendors" :selected="old('vendor_id', $expense->vendor_id)" placeholder="Not a registered vendor"/></x-field>
    @endif
    <x-field label="Paid to" name="payee_name"><x-input name="payee_name" :value="old('payee_name', $expense->payee_name)" placeholder="Shop, hotel, driver…"/></x-field>
    <x-field label="Receipt reference" name="receipt_reference"><x-input name="receipt_reference" :value="old('receipt_reference', $expense->receipt_reference)"/></x-field>
    @if ($canChooseClaimant)
        <x-field label="Claimant" name="claimant_id"><x-select name="claimant_id" :options="$people" :selected="old('claimant_id', $expense->claimant_id)" placeholder="Me"/></x-field>
    @endif
    <x-field label="Project" name="project_id"><x-select name="project_id" :options="$projects" :selected="old('project_id', $expense->project_id)" placeholder="No project"/></x-field>
    <x-field label="Organization unit" name="org_unit_id"><x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $expense->org_unit_id)" placeholder="No unit"/></x-field>
    @if ($costCenters !== [])
        <x-field label="Cost center" name="cost_center_id"><x-select name="cost_center_id" :options="$costCenters" :selected="old('cost_center_id', $expense->cost_center_id)" placeholder="No cost center"/></x-field>
    @endif
    <x-field label="Confidentiality" name="confidentiality" required><x-select name="confidentiality" :options="$confidentialities" :selected="old('confidentiality', $expense->confidentiality?->value ?? $expense->confidentiality)"/></x-field>
</div>
<x-field label="Description" name="description" class="mt-4"><x-textarea name="description" rows="3">{{ old('description', $expense->description) }}</x-textarea></x-field>
<x-field label="Notes" name="notes" class="mt-4"><x-textarea name="notes" rows="2">{{ old('notes', $expense->notes) }}</x-textarea></x-field>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="expense" :record="$expense" class="card mt-5"/>
