@props([
    'route',
    'filters' => [],
    'projects' => [],
    'statuses' => [],
    'placeholder' => 'Number or title',
])

{{--
    The filter bar shared by every engineering register.

    ONE BAR, FIVE REGISTERS, and the reason is not brevity. Search, project and status are the three
    axes every one of these registers is filtered on, and a person who learns the deliverable filter
    learns the RFI filter for free. Registers that need a fourth axis — drawing purpose, RFI kind,
    change origin — pass it in the `extra` slot rather than growing their own bar.

    `view` rides along as a hidden field because the tab strip and this form are one control split in
    two: filtering while looking at "late" must keep you looking at "late".

    Nothing here decides what is visible. The controller has already narrowed the query through the
    access engine; these inputs only narrow it further, and a value nobody may see cannot be revealed
    by typing it (§61).
--}}

@php
    $active = collect($filters)->except('view')->filter(fn ($v) => filled($v))->isNotEmpty();
@endphp

<form method="GET" action="{{ route($route) }}" class="card mb-5 p-4">
    <input type="hidden" name="view" value="{{ $filters['view'] ?? '' }}">

    <div class="grid gap-3 sm:grid-cols-4">
        <div class="sm:col-span-2">
            <label for="q" class="label">Search</label>
            <input type="search" name="q" id="q" class="input"
                   value="{{ $filters['q'] ?? '' }}" placeholder="{{ $placeholder }}">
        </div>

        @if (count($projects))
            <div>
                <label for="project" class="label">Project</label>
                <x-select name="project" :options="$projects" :selected="$filters['project'] ?? null"
                          placeholder="Any project"/>
            </div>
        @endif

        @if (count($statuses))
            <div>
                <label for="status" class="label">Status</label>
                <x-select name="status" :options="$statuses" :selected="$filters['status'] ?? null"
                          placeholder="Any status"/>
            </div>
        @endif

        {{ $extra ?? '' }}
    </div>

    <div class="mt-3 flex items-center gap-2">
        <button type="submit" class="btn btn-secondary">
            <x-icon name="search" class="h-3.5 w-3.5"/> Search
        </button>
        @if ($active)
            <a href="{{ route($route, ['view' => $filters['view'] ?? null]) }}" class="btn btn-ghost">Clear</a>
        @endif
    </div>
</form>
