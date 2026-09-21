{{--
    A department board (§53).

    Expects: $board, $heading, $subheading, $unit, $tiles, $canArrange, $units

    THE PICKER LISTS ONLY DEPARTMENTS THIS PERSON MAY OPEN, and it is built from the same
    `visibleTo` query the org chart is read under. A dropdown is a disclosure: a list naming every
    department in the company would tell a section head the shape of an organization the chart
    never showed them (§61). What is not in this list is also not reachable by editing the URL —
    the controller re-asks on every load.
--}}
<x-layouts.app :title="$unit?->name ?? 'Department board'">

    <x-page-header :title="$heading" :description="$subheading">
        <x-slot:actions>
            @if ($units->count() > 1)
                <form method="GET" action="{{ route('boards.department') }}"
                      x-data
                      class="flex items-center gap-2">
                    <label class="label sr-only" for="unit">Department</label>
                    <select id="unit" name="unit" class="select"
                            @change="window.location = '{{ route('boards.department', ['orgUnit' => '__ID__']) }}'.replace('__ID__', $event.target.value)">
                        @foreach ($units as $option)
                            <option value="{{ $option->id }}" @selected($unit && $unit->is($option))>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($unit === null)
        <div class="card flex flex-col items-center gap-3 p-10 text-center">
            <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-[var(--surface-muted)] text-[var(--text-muted)]">
                <x-icon name="building" class="h-5 w-5"/>
            </span>
            <div>
                <p class="font-medium text-[var(--text-strong)]">No department board to show you</p>
                <p class="mt-1 max-w-md text-sm text-[var(--text-muted)]">
                    You are not attached to a department, and none has been shared with you. Said
                    plainly rather than shown as an empty board, which would read as a department
                    with nothing in it.
                </p>
            </div>
        </div>
    @else
        @include('boards.partials.grid', ['tiles' => $tiles, 'canArrange' => $canArrange, 'board' => $board])
    @endif

</x-layouts.app>
