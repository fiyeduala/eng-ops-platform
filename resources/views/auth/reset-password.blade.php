<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('password.store') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="user" value="{{ $userId }}">
        <input type="hidden" name="token" value="{{ $token }}">

        <x-field label="New password" name="password" required
                 help="Longer is stronger. A short phrase you will remember beats a short scramble you will write down.">
            <x-input name="password" type="password" autocomplete="new-password" autofocus required/>
        </x-field>

        <x-field label="Confirm new password" name="password_confirmation" required>
            <x-input name="password_confirmation" type="password" autocomplete="new-password" required/>
        </x-field>

        <button type="submit" class="btn btn-primary w-full">Set password and sign in</button>
    </form>
</x-layouts.guest>
