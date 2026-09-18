<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Kind" name="kind" required help="An onboarding list is worked through when somebody joins; an exit list when they leave.">
        <x-select name="kind" :options="collect($kinds)->mapWithKeys(fn ($k) => [$k => ucfirst($k)])->all()" :selected="old('kind', $template->kind)"/>
    </x-field>
    <x-field label="Name" name="name" required><x-input name="name" :value="old('name', $template->name)" maxlength="160"/></x-field>
    <x-field label="Department" name="org_unit_id" help="Leave blank for a list the whole company uses.">
        <x-select name="org_unit_id" :options="$units" :selected="old('org_unit_id', $template->org_unit_id)" placeholder="Whole company"/>
    </x-field>
    <x-field label="Description" name="description"><x-input name="description" :value="old('description', $template->description)" maxlength="500"/></x-field>
</div>
<div class="mt-4 flex flex-wrap gap-6 text-sm">
    <label class="flex items-center gap-2"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template->is_active ?? true))> In use</label>
    {{-- One default per kind. An exit opened for somebody starts from whichever list this points at,
         so two defaults would make the answer depend on row order. --}}
    <label class="flex items-center gap-2"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $template->is_default ?? false))> Use this one by default</label>
</div>

<div class="mt-6" x-data="{ rows: {{ $items->isEmpty() ? '[{ label: \'\', description: \'\', responsible_role_id: \'\', requires_note: false }]' : json_encode($items->map(fn ($i) => ['label' => $i->label, 'description' => $i->description ?? '', 'responsible_role_id' => (string) ($i->responsible_role_id ?? ''), 'requires_note' => (bool) $i->requires_note])->values()) }} }">
    <div class="flex items-center justify-between">
        <h2 class="card-title">Steps</h2>
        <button type="button" class="btn btn-ghost btn-sm" @click="rows.push({ label: '', description: '', responsible_role_id: '', requires_note: false })">Add step</button>
    </div>
    <p class="mt-1 text-xs text-[var(--text-muted)]">The order here is the order people work through. Changing a list never changes a copy already handed to somebody.</p>

    <template x-for="(row, i) in rows" :key="i">
        <div class="mt-3 grid gap-2 rounded border border-[var(--border)] p-3 md:grid-cols-12">
            <input type="text" class="input md:col-span-4" maxlength="200" placeholder="What has to be done" x-model="row.label" :name="`items[${i}][label]`">
            <input type="text" class="input md:col-span-4" maxlength="500" placeholder="Detail (optional)" x-model="row.description" :name="`items[${i}][description]`">
            <select class="input md:col-span-2" x-model="row.responsible_role_id" :name="`items[${i}][responsible_role_id]`">
                <option value="">Anyone</option>
                @foreach ($roles as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
            {{-- No hidden partner: an unticked box simply sends nothing, and the controller reads a
                 missing key as false. A hidden field sharing the name would fight x-model. --}}
            <label class="flex items-center gap-2 text-xs md:col-span-1">
                <input type="checkbox" value="1" x-model="row.requires_note" :name="`items[${i}][requires_note]`"> Note
            </label>
            <button type="button" class="btn btn-ghost btn-sm md:col-span-1" @click="rows.splice(i, 1)">Remove</button>
        </div>
    </template>
</div>
