<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Code" name="code" required><x-input name="code" :value="old('code', $type->code)" maxlength="20"/></x-field>
    <x-field label="Name" name="name" required><x-input name="name" :value="old('name', $type->name)" maxlength="120"/></x-field>
    <x-field label="Days a year" name="days_per_year" help="Leave blank if this type is not counted against a balance.">
        <x-input type="number" step="0.5" min="0" max="366" name="days_per_year" :value="old('days_per_year', $type->days_per_year)"/>
    </x-field>
    <x-field label="Most days carried into next year" name="carry_over_limit">
        <x-input type="number" step="0.5" min="0" max="366" name="carry_over_limit" :value="old('carry_over_limit', $type->carry_over_limit)"/>
    </x-field>
    <x-field label="Longest single request (working days)" name="max_consecutive_days" help="Blank for no limit.">
        <x-input type="number" min="1" max="366" name="max_consecutive_days" :value="old('max_consecutive_days', $type->max_consecutive_days)"/>
    </x-field>
    <x-field label="Notice needed (calendar days)" name="min_notice_days">
        <x-input type="number" min="0" max="366" name="min_notice_days" :value="old('min_notice_days', $type->min_notice_days)"/>
    </x-field>
    <x-field label="Order in lists" name="sort"><x-input type="number" min="0" name="sort" :value="old('sort', $type->sort)"/></x-field>
    <div></div>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_paid" value="0"><input type="checkbox" name="is_paid" value="1" @checked(old('is_paid', $type->is_paid))> Paid</label>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="requires_approval" value="0"><input type="checkbox" name="requires_approval" value="1" @checked(old('requires_approval', $type->requires_approval))> Needs approval (the "Leave approval" workflow)</label>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="allow_half_days" value="0"><input type="checkbox" name="allow_half_days" value="1" @checked(old('allow_half_days', $type->allow_half_days))> Half days allowed</label>
    <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $type->is_active))> Active</label>
</div>
<x-field label="Description" name="description" class="mt-4"><x-textarea name="description" rows="3">{{ old('description', $type->description) }}</x-textarea></x-field>
