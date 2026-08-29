{{--
    Shared by create and edit.

    `$canReorganize` is the interesting one. Renaming a unit and MOVING it are different
    authorities (§3), because moving a department moves every person's visibility with it. When the
    actor lacks `org_unit.reorganize` the parent select is not rendered at all — and the controller
    discards any submitted parent_id in that case, so removing the control is a courtesy rather
    than the protection (§84).

    Expects: $unit, $types, $parents, $heads, $canReorganize
--}}
<div class="grid gap-5 p-5 sm:grid-cols-2">

    <x-field label="Name" name="name" required class="sm:col-span-2">
        <x-input name="name" :value="$unit->name" required autofocus/>
    </x-field>

    <x-field label="Level" name="type" required
             help="Divisions contain departments, departments contain sections, sections contain teams.">
        <x-select name="type" :options="$types" :selected="$unit->type?->value" required/>
    </x-field>

    <x-field label="Code" name="code" help="A short identifier used in document numbers and reports.">
        <x-input name="code" :value="$unit->code"/>
    </x-field>

    @if ($canReorganize)
        <x-field label="Reports into" name="parent_id" class="sm:col-span-2"
                 help="Moving a unit moves everyone in it, and changes who can see their work.">
            <x-select name="parent_id" :options="$parents" :selected="$unit->parent_id"
                      placeholder="Nothing — this is a top-level unit"/>
        </x-field>
    @else
        <div class="sm:col-span-2">
            <span class="label">Reports into</span>
            <p class="input flex items-center bg-[var(--surface-muted)] text-[var(--text-muted)]">
                {{ $unit->parent?->name ?? 'Nothing — this is a top-level unit' }}
            </p>
            <p class="help">
                Moving this unit needs the <span class="font-medium">org_unit.reorganize</span> permission over both
                where it is now and where it would go.
            </p>
        </div>
    @endif

    <x-field label="Head of unit" name="head_employee_id" class="sm:col-span-2"
             help="The person this unit reports through. Leaving it empty is fine — the unit simply has no appointed head.">
        @if ($heads === [])
            <p class="input flex items-center bg-[var(--surface-muted)] text-[var(--text-muted)]">
                You cannot see personnel records, so you cannot appoint a head.
            </p>
        @else
            <x-select name="head_employee_id" :options="$heads" :selected="$unit->head_employee_id"
                      placeholder="Not appointed"/>
        @endif
    </x-field>

    <x-field label="Description" name="description" class="sm:col-span-2">
        <x-textarea name="description" :value="$unit->description" rows="3"/>
    </x-field>

    <x-field label="Sort order" name="sort"
             help="Lower numbers appear first among units at the same level.">
        <x-input name="sort" type="number" :value="$unit->sort ?? 0" min="0" max="9999"/>
    </x-field>

    <div class="flex items-end">
        <label class="flex items-center gap-2 pb-2.5 text-sm text-[var(--text-body)]">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $unit->is_active ?? true))
                   class="h-4 w-4 rounded border-[var(--surface-border)]">
            Active
        </label>
    </div>
</div>
