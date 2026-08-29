@props(['title' => null, 'breadcrumbs' => []])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- The whole application is behind authentication; nothing here belongs in a search index. --}}
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title.' · ' : '' }}{{ auth()->user()?->organization?->name ?? config('app.name') }}</title>

    {{--
        Applied before first paint, on purpose. Reading the preference from Alpine after hydration
        would show a white flash to every dark-mode user on every navigation.
    --}}
    <script>
        try {
            const stored = localStorage.getItem('theme');
            const dark = stored ? stored === 'dark'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{--
        For the handful of rules a screen genuinely cannot express in utility classes — a parent
        state toggling the visibility of many children, say. Pushed from a view with @once, so it
        arrives after the compiled stylesheet and stays the exception rather than a second CSS file.
    --}}
    @stack('styles')
</head>
<body class="h-full">
<div class="min-h-full lg:flex" x-data="{ mobileNav: false }">

    {{-- Backdrop for the mobile drawer. --}}
    <div x-cloak x-show="mobileNav" x-transition.opacity @click="mobileNav = false"
         class="fixed inset-0 z-30 bg-slate-900/60 lg:hidden"></div>

    @include('partials.sidebar')

    <div class="flex min-w-0 flex-1 flex-col">
        @include('partials.topbar')

        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
            <div class="mx-auto w-full max-w-7xl">
                @if (! empty($breadcrumbs))
                    <nav aria-label="Breadcrumb" class="mb-3">
                        <ol class="flex flex-wrap items-center gap-1 text-xs text-[var(--text-muted)]">
                            @foreach ($breadcrumbs as $label => $url)
                                <li class="flex items-center gap-1">
                                    @if (! $loop->first)
                                        <x-icon name="chevron-right" class="h-3 w-3 opacity-60"/>
                                    @endif
                                    @if ($url && ! $loop->last)
                                        <a href="{{ $url }}" class="hover:text-[var(--text-strong)]">{{ $label }}</a>
                                    @else
                                        <span class="font-medium text-[var(--text-body)]">{{ $label }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </nav>
                @endif

                @include('partials.flash')

                {{ $slot }}
            </div>
        </main>

        <footer class="px-4 pb-6 pt-2 text-center text-xs text-[var(--text-muted)] sm:px-6 lg:px-8">
            {{ auth()->user()?->organization?->legal_name ?? config('app.name') }}
            &middot; {{ now()->year }}
        </footer>
    </div>
</div>
</body>
</html>
