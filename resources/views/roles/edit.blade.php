{{--
    Editing a role.

    Unlike an account, a role has no separate save for its permissions — the grants are the role, and
    splitting them into two forms would let somebody rename it and believe they had also saved the
    boxes they just ticked. One form, one Save, one audit entry.

    `$holderCount` is shown at the top rather than the bottom because it is the fact that changes what
    this screen means: editing a role held by forty people is editing forty accounts at once.
--}}
<x-layouts.app :title="'Edit '.$role->name">

    <x-page-header :title="'Edit '.$role->name" :description="$role->description">
        <x-slot:actions>
            <a href="{{ route('roles.show', $role) }}" class="btn btn-sm btn-ghost">Back to role</a>
        </x-slot:actions>
    </x-page-header>

    @if ($holderCount > 0)
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-500"/>
            <p>
                {{ $holderCount }} {{ Str::plural('account', $holderCount) }}
                {{ $holderCount === 1 ? 'holds' : 'hold' }} this role. Every change below takes effect for
                {{ $holderCount === 1 ? 'them' : 'all of them' }} the moment you save.
            </p>
        </div>
    @endif

    <form method="POST" action="{{ route('roles.update', $role) }}">
        @csrf
        @method('PUT')

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The role</h3>
            </div>

            @include('roles.partials.form')
        </div>

        <div class="card mt-6 overflow-hidden">
            <div class="card-header">
                <h3 class="card-title">What it can do</h3>
                <a href="{{ route('roles.show', $role) }}" class="btn btn-sm btn-ghost">See only what it carries</a>
            </div>

            @error('scope')
                <p class="error px-5 pt-4" role="alert">{{ $message }}</p>
            @enderror

            @include('roles.partials.grants')
        </div>

        <div class="mt-6 flex items-center justify-end gap-3">
            <a href="{{ route('roles.show', $role) }}" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save role</button>
        </div>
    </form>

</x-layouts.app>
