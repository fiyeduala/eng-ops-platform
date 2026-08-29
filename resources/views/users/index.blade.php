{{--
    Login accounts.

    An account is not a person — a person is an employee record. Plenty of people on a site have a
    personnel record and no way to sign in, and a few accounts (integrations, a departing
    administrator's handover) have no personnel record at all. Keeping the two separate is what
    makes "disable their access" and "record that they left" different actions.
--}}
<x-layouts.app title="Accounts">

    <x-page-header
        title="Accounts"
        description="Who can sign in, and what they hold. Creating an account never sets a password — the holder sets their own from an emailed link.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('users.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New account
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form method="GET" action="{{ route('users.index') }}" class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-end">
        <div class="flex-1">
            <label for="q" class="label">Search</label>
            <x-input name="q" :value="$filters['q']" placeholder="Name, email or username"/>
        </div>

        <div class="sm:w-56">
            <label for="status" class="label">Show</label>
            <x-select name="status" :options="$statuses" :selected="$filters['status']"/>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if ($filters['q'] || $filters['status'] !== 'active')
                <a href="{{ route('users.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    <div class="card overflow-hidden">
        @if ($users->isEmpty())
            <x-empty-state
                icon="key"
                title="No accounts to show"
                description="Nothing matches this filter, or the accounts it would match are outside your access."/>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Personnel record</th>
                            <th class="text-right">Roles</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $account)
                            <tr>
                                <td>
                                    <a href="{{ route('users.show', $account) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $account->name }}</a>
                                    <span class="mt-0.5 block text-xs text-[var(--text-muted)]">{{ $account->email }}</span>
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    @if ($account->employee)
                                        {{ $account->employee->fullName() }}
                                        <span class="block text-xs">{{ $account->employee->position?->title }}</span>
                                    @else
                                        <span class="text-xs">Not linked</span>
                                    @endif
                                </td>
                                <td class="text-right tabular-nums">{{ $account->roles_count }}</td>
                                <td>
                                    @if (! $account->is_active)
                                        <x-badge tone="neutral">Disabled</x-badge>
                                    @elseif ($account->isLocked())
                                        <x-badge tone="danger"><x-icon name="lock" class="h-3 w-3"/> Locked out</x-badge>
                                    @elseif ($account->must_change_password)
                                        <x-badge tone="warning">Must change password</x-badge>
                                    @else
                                        <x-badge tone="success">Active</x-badge>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('users.show', $account) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($users->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">{{ $users->links() }}</div>
            @endif
        @endif
    </div>

</x-layouts.app>
