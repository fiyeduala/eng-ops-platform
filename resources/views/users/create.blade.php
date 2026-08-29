<x-layouts.app title="New account">

    <x-page-header
        title="New account"
        description="Creating an account does not set a password. A one-time link is emailed to the address below and the holder chooses their own — so nobody, including you, ever knows it."/>

    <form method="POST" action="{{ route('users.store') }}" class="card max-w-3xl">
        @csrf

        @include('users.partials.form')

        <div class="border-t border-[var(--surface-border)] px-5 py-4">
            <div class="flex items-start gap-2 rounded-md bg-[var(--surface-muted)] p-3 text-xs text-[var(--text-muted)]">
                <x-icon name="info" class="mt-0.5 h-3.5 w-3.5 shrink-0"/>
                <p>
                    The new account holds no roles, so it can sign in and see almost nothing. Roles are assigned
                    afterwards, from the account’s edit page — a separate, separately audited decision.
                </p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-[var(--surface-border)] px-5 py-4">
            <a href="{{ route('users.index') }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Create and send invitation</button>
        </div>
    </form>

</x-layouts.app>
