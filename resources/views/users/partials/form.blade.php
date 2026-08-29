{{-- Expects: $account (a User, possibly unsaved) --}}
<div class="grid gap-5 p-5 sm:grid-cols-2">

    <x-field label="Full name" name="name" required class="sm:col-span-2">
        <x-input name="name" :value="$account->name" required autofocus autocomplete="off"/>
    </x-field>

    <x-field label="Email" name="email" required
             help="The address this account signs in with, and where the invitation is sent.">
        <x-input name="email" type="email" :value="$account->email" required autocomplete="off"/>
    </x-field>

    <x-field label="Username" name="username"
             help="Optional. An alternative to the email address at sign-in.">
        <x-input name="username" :value="$account->username" autocomplete="off"/>
    </x-field>

    <x-field label="Phone" name="phone">
        <x-input name="phone" type="tel" :value="$account->phone"/>
    </x-field>

    <x-field label="Time zone" name="timezone" help="Leave empty to follow the company default.">
        <x-select name="timezone"
                  :selected="$account->timezone"
                  placeholder="Company default"
                  :options="collect(timezone_identifiers_list())->mapWithKeys(fn ($tz) => [$tz => $tz])->all()"/>
    </x-field>

    <x-field label="Language" name="locale">
        <x-select name="locale" :selected="$account->locale" :options="['en' => 'English']" placeholder="Company default"/>
    </x-field>

    <div class="flex items-end">
        @if ($account->exists && (int) $account->id === (int) auth()->id())
            {{-- Disabling yourself locks you out mid-session and is the move of an administrator
                 ending their own audit trail. The controller drops is_active when the actor is the
                 subject, so the control is simply absent rather than present and ignored. --}}
            <p class="pb-2.5 text-xs text-[var(--text-muted)]">
                You cannot disable your own account.
            </p>
        @else
            <label class="flex items-center gap-2 pb-2.5 text-sm text-[var(--text-body)]">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active ?? true))
                       class="h-4 w-4 rounded border-[var(--surface-border)]">
                Account is active
            </label>
        @endif
    </div>
</div>
