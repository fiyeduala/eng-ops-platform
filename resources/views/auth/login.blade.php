<x-layouts.guest title="Sign in">
    <x-slot:subtitle>Use your work email address.</x-slot:subtitle>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <x-field label="Email address" name="email" required>
            <x-input name="email" type="email" autocomplete="username" autofocus required
                     inputmode="email" placeholder="you@company.com"/>
        </x-field>

        <x-field label="Password" name="password" required>
            <x-input name="password" type="password" autocomplete="current-password" required/>
        </x-field>

        <div class="flex items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                <input type="checkbox" name="remember" value="1"
                       class="h-4 w-4 rounded border-[var(--surface-border)] text-brand-600">
                Stay signed in
            </label>

            <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 hover:underline">
                Forgot password?
            </a>
        </div>

        <button type="submit" class="btn btn-primary w-full">Sign in</button>
    </form>

    <x-slot:footer>
        Accounts are created by your administrator.
    </x-slot:footer>
</x-layouts.guest>
