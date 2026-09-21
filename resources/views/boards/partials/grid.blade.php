{{--
    The tiles on a board, or the reason there are none.

    Expects: $tiles, $canArrange, $board

    EVERY TILE HERE WAS ALREADY DECIDED. This partial draws what WidgetComposer handed it and asks
    no questions of its own — no `@can`, no permission check, no filtering. A tile the viewer may
    not see never arrives, which is why there is nothing here to get wrong (§6). A template that
    re-checked would be a second implementation of the rule, and the second one is always the one
    that drifts.

    THE EMPTY STATE IS THE FIRST SCREEN A NEW COMPANY SEES, so it says what a board is for and
    points at the builder for whoever may use it. §73: a non-developer setting the platform up
    should be able to find the next step from the page they are standing on.
--}}
@if ($tiles === [])
    <div class="card flex flex-col items-center gap-3 p-10 text-center">
        <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-[var(--surface-muted)] text-[var(--text-muted)]">
            <x-icon name="grid" class="h-5 w-5"/>
        </span>
        <div>
            <p class="font-medium text-[var(--text-strong)]">Nothing on this board yet</p>
            <p class="mt-1 max-w-md text-sm text-[var(--text-muted)]">
                A board is arranged once and serves everybody: each person opening it sees the
                tiles they are allowed to see, carrying figures counted over the records they can
                open.
            </p>
        </div>
        @if ($canArrange)
            <a href="{{ route('configuration.widgets.create', ['board' => $board]) }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" class="h-3.5 w-3.5"/> Add the first tile
            </a>
        @endif
    </div>
@else
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
        @foreach ($tiles as $tile)
            <x-widget :tile="$tile"/>
        @endforeach
    </div>

    @if ($canArrange)
        <div class="mt-4 flex justify-end">
            <a href="{{ route('configuration.widgets.index') }}" class="btn btn-ghost btn-sm">
                <x-icon name="sliders" class="h-3.5 w-3.5"/> Arrange this board
            </a>
        </div>
    @endif
@endif
