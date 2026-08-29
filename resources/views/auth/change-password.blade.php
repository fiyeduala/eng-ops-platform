<x-layouts.app title="Change password" :breadcrumbs="['Dashboard' => route('dashboard'), 'Change password' => null]">
    <x-page-header title="Change your password"
                   :description="$forced
                        ? 'Your administrator issued this account a temporary password. Choose your own before continuing.'
                        : 'Changing your password signs you out everywhere else.'"/>

    @if ($forced)
        <div class="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0"/>
            <p>The rest of the application stays locked until this is done.</p>
        </div>
    @endif

    <div class="card max-w-lg p-6">
        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            @method('PUT')

            <x-field label="Current password" name="current_password" required>
                <x-input name="current_password" type="password" autocomplete="current-password" required/>
            </x-field>

            <hr class="border-[var(--surface-border)]">

            <x-field label="New password" name="password" required>
                <x-input name="password" type="password" autocomplete="new-password" required/>
            </x-field>

            <x-field label="Confirm new password" name="password_confirmation" required>
                <x-input name="password_confirmation" type="password" autocomplete="new-password" required/>
            </x-field>

            <div class="flex items-center gap-2 pt-1">
                <button type="submit" class="btn btn-primary">Change password</button>
                @unless ($forced)
                    <a href="{{ route('profile.show') }}" class="btn btn-secondary">Cancel</a>
                @endunless
            </div>
        </form>
    </div>
</x-layouts.app>
