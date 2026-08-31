@props(['route', 'views' => [], 'current' => null])

{{--
    The saved-view strip that sits above every engineering register.

    THESE ARE THE POINT OF THE REGISTERS, not decoration. A register with one undifferentiated list
    answers "what exists"; a register with "we owe an answer / awaiting their answer / late" answers
    the question somebody actually walked up to the screen with. Every register therefore opens on
    the view that names the work outstanding, not on everything.

    The current query string is carried across (minus `page`, because view 4 of the previous filter
    is meaningless once the filter changes), so switching views never silently discards a search.
--}}

<div class="mb-4 flex flex-wrap gap-1">
    @foreach ($views as $key => $label)
        <a href="{{ route($route, array_merge(request()->except(['view', 'page']), ['view' => $key])) }}"
           @class([
               'rounded-md px-3 py-1.5 text-sm',
               'bg-[var(--surface-muted)] font-medium text-[var(--text-strong)]' => $current === $key,
               'text-[var(--text-muted)] hover:text-[var(--text-body)]' => $current !== $key,
           ])>
            {{ $label }}
        </a>
    @endforeach
</div>
