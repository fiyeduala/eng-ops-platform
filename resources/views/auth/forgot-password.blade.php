<x-layouts.guest title="Reset your password">
    <x-slot:subtitle>We will email you a link to choose a new one.</x-slot:subtitle>

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <x-field label="Email address" name="email" required
                 help="Use the address your account was created with.">
            <x-input name="email" type="email" autocomplete="username" autofocus required
                     inputmode="email" placeholder="you@company.com"/>
        </x-field>

        <button type="submit" class="btn btn-primary w-full">Email me a link</button>
    </form>

    <x-slot:footer>
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:underline">Back to sign in</a>
    </x-slot:footer>
</x-layouts.guest>
