@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <script>
        try {
            const stored = localStorage.getItem('theme');
            const dark = stored ? stored === 'dark'
                : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        } catch (e) {}
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
<div class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
    <div class="mx-auto w-full max-w-sm">
        <div class="mb-6 flex flex-col items-center gap-3 text-center">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">
                {{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}
            </div>
            <div>
                <h1 class="text-base font-semibold text-[var(--text-strong)]">{{ $title ?? config('app.name') }}</h1>
                @isset($subtitle)
                    <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $subtitle }}</p>
                @endisset
            </div>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200">
                {{ session('status') }}
            </div>
        @endif

        <div class="card p-6">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="mt-4 text-center text-sm text-[var(--text-muted)]">{{ $footer }}</div>
        @endisset
    </div>
</div>
</body>
</html>
