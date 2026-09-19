@php
    $rows = old('items', $budget->exists ? $budget->items->map(fn ($line) => $line->only(['id', 'category', 'description', 'allocated_amount']))->all() : [
        ['category' => 'procurement', 'description' => '', 'allocated_amount' => ''],
    ]);
    while (count($rows) < 5) $rows[] = ['category' => '', 'description' => '', 'allocated_amount' => ''];
@endphp
<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Title" name="title" required><x-input name="title" :value="old('title', $budget->title)"/></x-field>
    <x-field label="Fiscal year" name="fiscal_year" required><x-input type="number" name="fiscal_year" :value="old('fiscal_year', $budget->fiscal_year)"/></x-field>
    <x-field label="Starts" name="starts_on" required><x-input type="date" name="starts_on" :value="old('starts_on', $budget->starts_on?->format('Y-m-d'))"/></x-field>
    <x-field label="Ends" name="ends_on" required><x-input type="date" name="ends_on" :value="old('ends_on', $budget->ends_on?->format('Y-m-d'))"/></x-field>
    <x-field label="Cost center" name="cost_center_id"><x-select name="cost_center_id" :options="$costCenters" :selected="old('cost_center_id', $budget->cost_center_id)" placeholder="No cost center"/></x-field>
    <x-field label="Owner" name="owner_id"><x-select name="owner_id" :options="$owners" :selected="old('owner_id', $budget->owner_id)" placeholder="No named owner"/></x-field>
    <x-field label="Project" name="project_id"><x-select name="project_id" :options="$projects" :selected="old('project_id', $budget->project_id)" placeholder="Organization / unit budget"/></x-field>
    <x-field label="Organization unit" name="org_unit_id"><x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $budget->org_unit_id)" placeholder="Organization-wide"/></x-field>
    <x-field label="Confidentiality" name="confidentiality" required><x-select name="confidentiality" :options="$confidentialities" :selected="old('confidentiality', $budget->confidentiality?->value ?? $budget->confidentiality)"/></x-field>
</div>
<x-field label="Notes" name="notes" class="mt-4"><x-textarea name="notes" rows="3">{{ old('notes', $budget->notes) }}</x-textarea></x-field>
<div class="mt-6">
    <h2 class="card-title">Allocation lines</h2>
    <p class="mt-1 text-sm text-[var(--text-muted)]">Use category “procurement” to compare a line with costs posted from accepted deliveries.</p>
    @error('items')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror
    <div class="mt-3 overflow-x-auto"><table class="table"><thead><tr><th>Category</th><th>Description</th><th>Allocated amount</th></tr></thead><tbody>
        @foreach($rows as $index => $row)
            <tr>
                <td><input type="hidden" name="items[{{ $index }}][id]" value="{{ $row['id'] ?? '' }}"><x-input name="items[{{ $index }}][category]" :value="$row['category'] ?? ''" placeholder="procurement"/></td>
                <td><x-input name="items[{{ $index }}][description]" :value="$row['description'] ?? ''"/></td>
                <td><x-input type="number" step="0.01" min="0" name="items[{{ $index }}][allocated_amount]" :value="$row['allocated_amount'] ?? ''"/></td>
            </tr>
        @endforeach
    </tbody></table></div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="budget" :record="$budget" class="card mt-5"/>
