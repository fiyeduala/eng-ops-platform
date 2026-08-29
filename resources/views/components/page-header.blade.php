@props(['title', 'description' => null])

<div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
        <h2 class="text-lg font-semibold tracking-tight text-[var(--text-strong)]">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 max-w-2xl text-sm text-[var(--text-muted)]">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
