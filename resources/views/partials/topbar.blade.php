@php
    /** @var \App\Models\User $me */
    $me = auth()->user();
@endphp

<header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-[var(--surface-border)] bg-[var(--surface-card)] px-4 sm:px-6 lg:px-8">
    <button type="button" @click="mobileNav = true"
            class="btn btn-ghost -ml-2 px-2 lg:hidden" aria-label="Open navigation">
        <x-icon name="menu" class="h-5 w-5"/>
    </button>

    <div class="min-w-0 flex-1">
        @isset($title)
            <h1 class="truncate text-sm font-semibold text-[var(--text-strong)]">{{ $title }}</h1>
        @endisset
    </div>

    <button type="button" @click="$store.theme.toggle()" class="btn btn-ghost px-2"
            :aria-label="$store.theme.dark ? 'Switch to light theme' : 'Switch to dark theme'">
        <x-icon name="sun" class="hidden h-4 w-4 dark:block"/>
        <x-icon name="moon" class="h-4 w-4 dark:hidden"/>
    </button>

    <div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
        <button type="button" @click="open = !open" :aria-expanded="open"
                class="flex items-center gap-2 rounded-lg px-1.5 py-1 hover:bg-[var(--surface-muted)]">
            <span class="flex h-7 w-7 items-center justify-center rounded-full bg-brand-600 text-[11px] font-semibold text-white">
                {{ $me->initials() }}
            </span>
            <x-icon name="chevron-down" class="h-3.5 w-3.5 text-[var(--text-muted)]"/>
        </button>

        <div x-cloak x-show="open" @click.outside="open = false" x-transition.origin.top.right
             class="card absolute right-0 mt-2 w-60 overflow-hidden p-0 text-sm shadow-lg">
            <div class="border-b border-[var(--surface-border)] px-3 py-2.5">
                <p class="truncate font-medium text-[var(--text-strong)]">{{ $me->name }}</p>
                <p class="truncate text-xs text-[var(--text-muted)]">{{ $me->email }}</p>
            </div>
            <div class="p-1">
                <a href="{{ route('profile.show') }}" class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-[var(--surface-muted)]">
                    <x-icon name="user" class="h-4 w-4 text-[var(--text-muted)]"/> My profile
                </a>
                <a href="{{ route('password.edit') }}" class="flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-[var(--surface-muted)]">
                    <x-icon name="lock" class="h-4 w-4 text-[var(--text-muted)]"/> Change password
                </a>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="border-t border-[var(--surface-border)] p-1">
                @csrf
                <button type="submit" class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left hover:bg-[var(--surface-muted)]">
                    <x-icon name="logout" class="h-4 w-4 text-[var(--text-muted)]"/> Sign out
                </button>
            </form>
        </div>
    </div>
</header>
