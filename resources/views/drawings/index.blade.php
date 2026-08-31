{{--
    The drawing register — the title block, sorted by number.

    SORTED BY NUMBER AND NOTHING ELSE. Every other register here opens on what is late, because that
    is the question people bring to it. A drawing register is a lookup table: somebody has a number
    in their hand — off a mark-up, out of a site query, quoted in a phone call — and wants the line.
    Reordering it by date would break the one habit the register is used for.

    "NO FILE YET" IS A FIRST-CLASS VIEW because it is the register's honest state for months. Lines
    are opened when the drawing list is agreed and stay empty until somebody draws them, and the gap
    between the two is exactly what a document controller is chasing.

    Expects: $drawings, $statuses, $projects, $units, $purposes, $views, $filters, $canCreate
--}}
<x-layouts.app title="Drawings">

    <x-page-header
        title="Drawings"
        description="Number, title, sheet, revision and issue purpose. The controlled file itself lives in document control — this register points at it.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('drawings.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New drawing
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="drawings.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="drawings.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Drawing number or title">
        <x-slot:extra>
            <div>
                <label for="purpose" class="label">Issue purpose</label>
                <x-select name="purpose" :options="$purposes" :selected="$filters['purpose']"
                          placeholder="Any purpose"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$drawings" icon="pencil"
                      title="No drawings"
                      description="Either nothing matches, or the drawings that do belong to projects your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('drawings.create') }}" class="btn btn-primary btn-sm">Add a drawing</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>Title</th>
                <th>Sheet</th>
                <th>Rev</th>
                <th>Purpose</th>
                <th>Drawn / checked</th>
                <th>File</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($drawings as $drawing)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('drawings.show', $drawing) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $drawing->number }}
                        </a>
                    </td>

                    <td>
                        <a href="{{ route('drawings.show', $drawing) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $drawing->title }}
                        </a>
                        @if ($drawing->project)
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $drawing->project->displayName() }}
                                @if ($drawing->discipline) · {{ $drawing->discipline->name }} @endif
                            </span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $drawing->sheetLabel() ?? '—' }}
                        @if ($drawing->sheet_size)
                            <span class="block text-xs">{{ $drawing->sheet_size }}</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        <span class="font-mono text-sm text-[var(--text-strong)]">{{ $drawing->revision ?? '—' }}</span>
                        @if ($drawing->revision_date)
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $drawing->revision_date->format('j M y') }}
                            </span>
                        @endif
                    </td>

                    <td class="text-[var(--text-muted)]">
                        {{ $purposes[$drawing->purpose] ?? ($drawing->purpose ? \Illuminate\Support\Str::headline($drawing->purpose) : '—') }}
                    </td>

                    <td class="text-[var(--text-muted)]">
                        {{ $drawing->drawnBy?->name ?? '—' }}
                        <span class="block text-xs">
                            {{ $drawing->checkedBy ? 'checked by '.$drawing->checkedBy->name : 'not checked' }}
                        </span>
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($drawing->hasFile())
                            <x-badge tone="success">Attached</x-badge>
                        @else
                            <x-badge tone="warning">No file</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
