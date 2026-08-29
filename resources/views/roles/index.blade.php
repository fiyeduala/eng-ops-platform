{{--
    Role definitions.

    This is where a company writes its own access model, so the list leads with what a role costs
    rather than what it is called: how many permissions it carries and how many people hold it. A
    role held by sixty people is not a definition any more, it is a decision about sixty accounts.
--}}
<x-layouts.app title="Roles">

    <x-page-header
        title="Roles"
        description="A role is a named bundle of permissions. Assigning one to somebody, and pinning it to a unit, is done from their account.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('roles.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New role
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('roles.index') }}" class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="q" class="label">Search</label>
            <x-input name="q" :value="$filters['q']" placeholder="Name or reference name"/>
        </div>

        <label class="flex items-center gap-2 pb-2.5 text-sm text-[var(--text-body)]">
            <input type="checkbox" name="include_inactive" value="1" @checked($filters['include_inactive'])
                   class="h-4 w-4 rounded border-[var(--surface-border)]">
            Include withdrawn
        </label>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if ($filters['q'] || $filters['include_inactive'])
                <a href="{{ route('roles.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- list --}}
    <div class="card overflow-hidden">
        @if ($roles->isEmpty())
            <x-empty-state
                icon="key"
                title="No roles match"
                description="Either nothing matches this filter, or you cannot see any role definitions. Both look the same from here.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('roles.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Create the first role
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Role</th>
                            {{-- "Grants", not "Permissions": one permission held on both the
                                 organizational and the project axis is two rows on the pivot, and
                                 rounding that down to one would understate the role's reach. --}}
                            <th class="text-right">Grants</th>
                            <th class="text-right">Held by</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($roles as $role)
                            <tr class="{{ $role->is_active ? '' : 'opacity-60' }}">
                                <td>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <a href="{{ route('roles.show', $role) }}"
                                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $role->name }}</a>
                                        @if ($role->is_system)
                                            <x-badge tone="neutral">Built in</x-badge>
                                        @endif
                                        @unless ($role->is_active)
                                            <x-badge tone="neutral">Withdrawn</x-badge>
                                        @endunless
                                    </div>
                                    @if ($role->description)
                                        <p class="mt-0.5 max-w-xl text-xs text-[var(--text-muted)]">{{ $role->description }}</p>
                                    @endif
                                    <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $role->slug }}</span>
                                </td>
                                <td class="text-right tabular-nums">{{ $role->permissions_count }}</td>
                                <td class="text-right tabular-nums">{{ $role->users_count }}</td>
                                <td class="text-right">
                                    <a href="{{ route('roles.show', $role) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($roles->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">
                    {{ $roles->links() }}
                </div>
            @endif
        @endif
    </div>

</x-layouts.app>
