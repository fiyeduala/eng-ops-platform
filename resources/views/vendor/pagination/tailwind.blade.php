{{--
    Pagination.

    Overrides Laravel's bundled `pagination::tailwind`, which ships Tailwind v3 class names — this
    project is on v4, where several of them no longer exist and the control renders unstyled. Built
    from the same .btn vocabulary as the rest of the application instead, so it also inherits the
    theme without a second set of colour decisions.
--}}
@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <p class="text-xs text-[var(--text-muted)]">
            Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}
        </p>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="btn btn-sm btn-ghost cursor-default opacity-40" aria-disabled="true">
                    <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Previous
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-sm btn-ghost">
                    <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Previous
                </a>
            @endif

            <div class="hidden items-center gap-1 sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="px-2 text-sm text-[var(--text-muted)]">{{ $element }}</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="btn btn-sm btn-secondary cursor-default" aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="btn btn-sm btn-ghost">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-sm btn-ghost">
                    Next <x-icon name="chevron-right" class="h-3.5 w-3.5"/>
                </a>
            @else
                <span class="btn btn-sm btn-ghost cursor-default opacity-40" aria-disabled="true">
                    Next <x-icon name="chevron-right" class="h-3.5 w-3.5"/>
                </span>
            @endif
        </div>
    </nav>
@endif
