{{--
    One box across every register (§61).

    Expects: $term, $usable, $entityType, $entityLabel, $groups, $records (paginator or null), $minimum

    WHAT IS NOT ON THIS PAGE IS THE PART THAT MATTERS. There is no list of registers to search in,
    no "0 results" heading for a module the reader has no permission for, and no count of things
    they cannot open. A register they cannot read is simply not here, because a heading is a
    disclosure — it names a module, spells it the company's way, and reports whether a term matched
    inside it. Everything rendered below is a record this reader could have reached by opening the
    register's own index page and typing the same words.
--}}
<x-layouts.app title="Search">

    <x-page-header
        title="Search"
        :description="$entityLabel ? 'In '.$entityLabel : 'Everything you can open, in one place'">
        @if ($entityType)
            <x-slot:actions>
                <a href="{{ route('search', ['q' => $term]) }}" class="btn btn-sm btn-ghost">
                    <x-icon name="chevron-left" class="h-3.5 w-3.5"/> All registers
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <form method="GET" action="{{ route('search') }}" class="card mb-6">
        <div class="flex flex-wrap items-center gap-3 p-4">
            @if ($entityType)
                <input type="hidden" name="type" value="{{ $entityType }}">
            @endif

            <label for="q" class="sr-only">What are you looking for?</label>
            <input
                id="q"
                type="search"
                name="q"
                value="{{ $term }}"
                autofocus
                placeholder="A number, a title, a name…"
                class="input flex-1 min-w-[12rem]">

            <button type="submit" class="btn btn-sm btn-primary">
                <x-icon name="search" class="h-3.5 w-3.5"/> Search
            </button>
        </div>
    </form>

    @if (! $usable)
        <x-empty-state
            icon="search"
            title="Type at least {{ $minimum }} characters"
            description="Search looks through the numbers, titles and names of every register you can open. A single letter matches most of a database, which is not an answer."/>

    @elseif ($records !== null)
        {{-- ------------------------------------------------------ one register, paginated --}}
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">{{ $entityLabel }}</h3>
                <span class="text-sm text-[var(--text-muted)]">
                    {{ number_format($records->total()) }} {{ \Illuminate\Support\Str::plural('match', $records->total()) }}
                </span>
            </div>

            @if ($records->total() === 0)
                <x-empty-state
                    icon="empty"
                    title="Nothing here matches"
                    description="No record you can see in this register matches those words. Somebody with wider access may see more."/>
            @else
                <ul class="p-5">
                    @foreach ($records as $record)
                        @php $href = \App\Support\RecordLinks::for($record); @endphp
                        <li class="border-b border-[var(--surface-border)] py-2 last:border-0">
                            @if ($href)
                                <a href="{{ $href }}" class="text-sm text-[var(--text-strong)] hover:text-brand-600">
                                    {{ \App\Support\RecordPresenter::describe($record) }}
                                </a>
                            @else
                                <span class="text-sm text-[var(--text-strong)]">{{ \App\Support\RecordPresenter::describe($record) }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($records->hasPages())
                    <div class="p-4">{{ $records->links() }}</div>
                @endif
            @endif
        </div>

    @elseif ($groups === [])
        <x-empty-state
            icon="empty"
            title="Nothing matches “{{ $term }}”"
            description="No record you can open carries those words in its number, title or name. If you were expecting something here, somebody with wider access may be able to find it."/>

    @else
        {{-- ------------------------------------------------------ every register, a handful each --}}
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach ($groups as $group)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">{{ $group->label }}</h3>
                    </div>

                    <ul class="p-5">
                        @foreach ($group->records as $record)
                            @php $href = \App\Support\RecordLinks::for($record); @endphp
                            <li class="border-b border-[var(--surface-border)] py-2 last:border-0">
                                @if ($href)
                                    <a href="{{ $href }}" class="text-sm text-[var(--text-strong)] hover:text-brand-600">
                                        {{ \App\Support\RecordPresenter::describe($record) }}
                                    </a>
                                @else
                                    <span class="text-sm text-[var(--text-strong)]">{{ \App\Support\RecordPresenter::describe($record) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($group->more)
                        <div class="px-5 pb-4">
                            <a href="{{ route('search', ['q' => $term, 'type' => $group->entityType]) }}"
                               class="inline-flex items-center gap-1 text-xs font-medium text-[var(--text-strong)] hover:underline">
                                See all matching {{ \Illuminate\Support\Str::lower($group->label) }}
                                <x-icon name="chevron-right" class="h-3 w-3"/>
                            </a>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</x-layouts.app>
