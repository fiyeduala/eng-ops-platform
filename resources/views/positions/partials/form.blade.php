{{-- Expects: $position, $units --}}
<div class="grid gap-5 p-5 sm:grid-cols-2">

    <x-field label="Title" name="title" required class="sm:col-span-2">
        <x-input name="title" :value="$position->title" required autofocus placeholder="Senior Mechanical Engineer"/>
    </x-field>

    <x-field label="Unit" name="org_unit_id" required
             help="Which part of the organization this title belongs to. Only units you can see are listed.">
        <x-select name="org_unit_id" :options="$units" :selected="$position->org_unit_id" placeholder="Choose a unit" required/>
    </x-field>

    <x-field label="Code" name="code" help="Short identifier, e.g. ENG-SME-01.">
        <x-input name="code" :value="$position->code"/>
    </x-field>

    <x-field label="Grade" name="grade" help="Your company’s own banding. Free text — nothing is derived from it.">
        <x-input name="grade" :value="$position->grade"/>
    </x-field>

    <div class="flex flex-col justify-end gap-2 pb-2">
        <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
            <input type="hidden" name="is_management" value="0">
            <input type="checkbox" name="is_management" value="1" @checked(old('is_management', $position->is_management ?? false))
                   class="h-4 w-4 rounded border-[var(--surface-border)]">
            This is a management position
        </label>
        <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $position->is_active ?? true))
                   class="h-4 w-4 rounded border-[var(--surface-border)]">
            Active
        </label>
    </div>

    <x-field label="Description" name="description" class="sm:col-span-2"
             help="What this role is responsible for. Shown on the personnel record of anyone holding it.">
        <x-textarea name="description" :value="$position->description" rows="4"/>
    </x-field>
</div>
