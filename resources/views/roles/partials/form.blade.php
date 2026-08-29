{{-- Expects: $role (a Role, possibly unsaved) --}}
<div class="grid gap-5 p-5 sm:grid-cols-2">

    <x-field label="Name" name="name" required class="sm:col-span-2">
        <x-input name="name" :value="$role->name" required autofocus autocomplete="off"/>
    </x-field>

    <x-field label="Description" name="description" class="sm:col-span-2"
             help="What this role is for, in the words your organization actually uses. It is shown wherever the role is offered.">
        <textarea name="description" id="description" rows="2"
                  class="input {{ $errors->has('description') ? 'input-error' : '' }}">{{ old('description', $role->description) }}</textarea>
    </x-field>

    <x-field label="Reference name" name="slug"
             help="Lower case, letters, numbers and hyphens. Left empty it is generated from the name. Templates and imports find the role by this, so it survives a rename.">
        {{-- Readonly rather than disabled: a disabled field submits nothing, and "nothing" would
             read as "clear the slug" on a role the application looks up by that exact name. --}}
        @if ($role->is_system)
            <x-input name="slug" :value="$role->slug" readonly autocomplete="off"/>
        @else
            <x-input name="slug" :value="$role->slug" autocomplete="off"
                     placeholder="site-administrator" pattern="[a-z0-9\-]+"/>
        @endif
    </x-field>

    <x-field label="Seniority" name="level"
             help="Ordering and display only, 1–100. A high number does not grant access — only the permissions below do.">
        <x-input name="level" type="number" min="1" max="100" :value="$role->level ?? 50"/>
    </x-field>

    <div class="sm:col-span-2">
        <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $role->is_active ?? true))
                   class="h-4 w-4 rounded border-[var(--surface-border)]">
            Available for assignment
        </label>
        <p class="help">
            Turning this off hides the role from the assignment screens. It does not remove it from anyone
            already holding it — that has to be done account by account, deliberately.
        </p>
    </div>

    @if ($role->is_system)
        <div class="sm:col-span-2">
            <div class="flex items-start gap-2 rounded-md bg-[var(--surface-muted)] p-3 text-xs text-[var(--text-muted)]">
                <x-icon name="lock" class="mt-0.5 h-3.5 w-3.5 shrink-0"/>
                <p>
                    This is a built-in role. Its permissions can be changed to suit your organization, but its
                    reference name is fixed and it cannot be deleted — parts of the application look for it by name.
                </p>
            </div>
        </div>
    @endif
</div>
