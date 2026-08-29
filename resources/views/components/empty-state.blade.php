@props(['title' => 'Nothing here yet', 'description' => null, 'icon' => 'empty'])

{{--
    Shown when a list is empty. The wording matters more than it looks: in a system where lists
    are permission-filtered, "no records" and "no records you can see" are different facts, and
    telling someone their list is empty when it is merely filtered sends them to support. Callers
    pass the honest description.
--}}

<div class="flex flex-col items-center justify-center px-6 py-14 text-center">
    <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-[var(--surface-muted)]">
        <x-icon :name="$icon" class="h-5 w-5 text-[var(--text-muted)]"/>
    </div>
    <p class="text-sm font-medium text-[var(--text-strong)]">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-[var(--text-muted)]">{{ $description }}</p>
    @endif
    @isset($actions)
        <div class="mt-4 flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
