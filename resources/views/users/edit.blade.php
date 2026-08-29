{{--
    Editing an account, and — separately — the roles it holds.

    Two forms, deliberately. Correcting a phone number and granting somebody company-wide access are
    not the same decision and should not share a Save button; they post to different routes, are
    authorized by different abilities, and are audited as different events.
--}}
<x-layouts.app :title="'Edit '.$account->name">

    <x-page-header :title="'Edit '.$account->name" :description="$account->email">
        <x-slot:actions>
            <a href="{{ route('users.show', $account) }}" class="btn btn-sm btn-ghost">Back to account</a>
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- details --}}
    <form method="POST" action="{{ route('users.update', $account) }}" class="card max-w-3xl">
        @csrf
        @method('PUT')

        <div class="card-header">
            <h3 class="card-title">Account details</h3>
        </div>

        @include('users.partials.form')

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('users.show', $account) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save account</button>
        </div>
    </form>

    {{-- ---------------------------------------------------------------- roles --}}
    @if ($canAssignRoles)
        <form method="POST" action="{{ route('users.roles.update', $account) }}" id="roles" class="card mt-6 max-w-3xl">
            @csrf
            @method('PUT')

            <div class="card-header">
                <h3 class="card-title">Roles</h3>
                <span class="text-xs text-[var(--text-muted)]">Saved separately, audited separately</span>
            </div>

            <div class="border-b border-[var(--surface-border)] px-5 py-4">
                <div class="flex items-start gap-2 rounded-md bg-[var(--surface-muted)] p-3 text-xs text-[var(--text-muted)]">
                    <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0"/>
                    <p>
                        Leaving a role unpinned gives it company-wide reach. Pinning it to a unit narrows every
                        permission the role carries to that unit and everything below it — which is how one
                        “Administrator” definition serves both a company-wide and a departmental administrator.
                    </p>
                </div>
            </div>

            @error('roles')
                <p class="error px-5 pt-4" role="alert">{{ $message }}</p>
            @enderror

            @if ($assignable->isEmpty())
                <x-empty-state
                    icon="key"
                    title="No roles available to you"
                    description="You can change this account’s roles, but there are no role definitions within your access to assign."/>
            @else
                <ul class="divide-y divide-[var(--surface-border)]">
                    @foreach ($assignable as $role)
                        @php
                            $held = array_key_exists($role->id, $assignments);
                            $pinned = old('scope_id.'.$role->id, $held ? $assignments[$role->id]['scope_id'] : null);
                            $locked = ! $role->grantable;

                            // After a failed submission the form redraws from old() rather than from
                            // the database, so a half-made change is not silently reverted.
                            $ticked = old('roles') !== null
                                ? in_array((string) $role->id, array_map('strval', (array) old('roles', [])), true)
                                : $held;
                        @endphp

                        <li class="px-5 py-4" x-data="{ held: @js($ticked), unit: @js((string) ($pinned ?? '')) }">

                            <div class="flex items-start gap-3">
                                @if ($locked && $held)
                                    {{-- Held, but carrying permissions this actor does not hold. It is preserved
                                         verbatim rather than offered: the form replaces every assignment at once,
                                         so a role merely left out of the markup would be silently stripped. --}}
                                    <input type="hidden" name="roles[]" value="{{ $role->id }}">
                                    @if ($pinned)
                                        <input type="hidden" name="scope_type[{{ $role->id }}]" value="org_unit">
                                        <input type="hidden" name="scope_id[{{ $role->id }}]" value="{{ $pinned }}">
                                    @endif
                                    <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
                                @else
                                    <input type="checkbox"
                                           name="roles[]"
                                           value="{{ $role->id }}"
                                           id="role-{{ $role->id }}"
                                           x-model="held"
                                           @disabled($locked)
                                           class="mt-0.5 h-4 w-4 shrink-0 rounded border-[var(--surface-border)] disabled:opacity-40">
                                @endif

                                <div class="min-w-0 flex-1">
                                    <label for="role-{{ $role->id }}" class="flex flex-wrap items-center gap-2 text-sm font-medium text-[var(--text-strong)]">
                                        {{ $role->name }}
                                        @if ($role->level >= 80)
                                            <x-badge tone="warning">High authority</x-badge>
                                        @endif
                                        @if ($locked)
                                            <x-badge tone="neutral">Beyond your own access</x-badge>
                                        @endif
                                    </label>

                                    @if ($role->description)
                                        <p class="mt-0.5 text-xs text-[var(--text-muted)]">{{ $role->description }}</p>
                                    @endif

                                    @if ($locked)
                                        <p class="mt-1 text-xs text-[var(--text-muted)]">
                                            @if ($held)
                                                This account holds this role. It carries permissions you do not hold yourself,
                                                so you can neither remove it nor change what it is limited to.
                                            @else
                                                This role carries permissions you do not hold yourself, so you cannot grant it.
                                            @endif
                                        </p>
                                    @else
                                        <div x-show="held" x-cloak class="mt-3">
                                            <label for="scope-{{ $role->id }}" class="label">Limit this assignment to</label>
                                            <select name="scope_id[{{ $role->id }}]"
                                                    id="scope-{{ $role->id }}"
                                                    x-model="unit"
                                                    class="select {{ $errors->has('scope_id.'.$role->id) ? 'input-error' : '' }}">
                                                <option value="">Nothing — company-wide</option>
                                                @foreach ($units as $unitId => $unitLabel)
                                                    <option value="{{ $unitId }}" @selected((string) $pinned === (string) $unitId)>{{ $unitLabel }}</option>
                                                @endforeach
                                            </select>

                                            {{-- scope_type is derived, never typed. A pin with no unit is the exact
                                                 opposite of what the administrator selected, so the two always agree. --}}
                                            <input type="hidden" name="scope_type[{{ $role->id }}]" :value="unit ? 'org_unit' : ''">

                                            @error('scope_id.'.$role->id)
                                                <p class="error" role="alert">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
                <button type="submit" class="btn btn-primary">Save roles</button>
            </div>
        </form>
    @endif

</x-layouts.app>
