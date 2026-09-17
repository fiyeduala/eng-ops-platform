<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Code" name="code" required><x-input name="code" :value="old('code', $code->code)" maxlength="20"/></x-field>
    <x-field label="Name" name="name" required><x-input name="name" :value="old('name', $code->name)"/></x-field>
    <x-field label="Rate (%)" name="rate" required help="Changing a rate never alters records already raised."><x-input type="number" step="0.0001" min="0" max="100" name="rate" :value="old('rate', $code->rate)"/></x-field>
    <x-field label="Applies to" name="applies_to" required><x-select name="applies_to" :options="\App\Models\TaxCode::APPLIES_TO" :selected="old('applies_to', $code->applies_to)"/></x-field>
    <label class="flex items-center gap-2"><input type="checkbox" name="is_recoverable" value="1" @checked(old('is_recoverable', $code->is_recoverable))> Recoverable on purchases (tax is not a project cost)</label>
    <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $code->is_active))> Active</label>
</div>
<x-field label="Description" name="description" class="mt-4"><x-textarea name="description" rows="3">{{ old('description', $code->description) }}</x-textarea></x-field>
