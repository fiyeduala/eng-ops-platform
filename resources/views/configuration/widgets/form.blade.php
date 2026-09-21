{{--
    Adding a tile to a board.

    Expects: $widget, $boards, $kinds, $refreshOptions, $widths, $maxRows, $maxSpan, $measures, $reports

    THERE IS NO PERMISSION FIELD ON THIS FORM and there never will be. A tile is shown to whoever may
    read the register behind it, worked out at render time from the measure or report it draws from.
    A select box here offering "who can see this" would let somebody publish a figure under a
    permission the register itself never agreed to, and the tile would look correct in every other
    respect (§6, §84).

    THE ONE CHOICE WORTH EXPLAINING ON THE SCREEN is "counted now" against "last night's reading",
    so it is explained on the screen rather than only in the code. Asking for last night's reading
    is a request, not a grant: anybody whose view of the company falls short of the whole board's
    scope gets their own figure counted live, with a sentence saying so.
--}}
<x-layouts.app :title="$widget->exists ? 'Edit tile' : 'New tile'">

    <x-page-header
        :title="$widget->exists ? 'Edit tile' : 'New tile'"
        description="What this tile shows, which board it sits on, and how its figure is obtained.">
        <x-slot:actions>
            <a href="{{ route('configuration.widgets.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Boards
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That tile was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $widget->exists
              ? route('configuration.widgets.update', $widget)
              : route('configuration.widgets.store') }}"
          x-data="{ kind: '{{ old('kind', $widget->kind ?? 'kpi_tile') }}' }">
        @csrf
        @if ($widget->exists)
            @method('PUT')
        @endif

        <div class="card mb-5">
            <div class="card-header">
                <p class="card-title">Where it goes, and what it is</p>
            </div>

            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="board">Board</label>
                    <select id="board" name="board" class="select">
                        @foreach ($boards as $key => $board)
                            <option value="{{ $key }}"
                                @selected(old('board', $widget->board) === $key)>{{ $board['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="help">Each board is about one altitude: the company, a department, a project, or one person's landing page.</p>
                </div>

                <div>
                    <label class="label" for="kind">Kind of tile</label>
                    <select id="kind" name="kind" class="select" x-model="kind">
                        @foreach ($kinds as $key => $kind)
                            <option value="{{ $key }}"
                                @selected(old('kind', $widget->kind) === $key)>{{ $kind['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="help">A trend can only go on a board about a company, department or project — one person is not a scope anybody else can be shown.</p>
                </div>

                <div x-show="kind === 'kpi_tile' || kind === 'kpi_trend'" x-cloak>
                    <label class="label" for="kpi_definition_id">Measure</label>
                    <select id="kpi_definition_id" name="kpi_definition_id" class="select">
                        <option value="">Choose a measure…</option>
                        @foreach ($measures as $measure)
                            <option value="{{ $measure->id }}"
                                @selected((int) old('kpi_definition_id', $widget->kpi_definition_id) === (int) $measure->id)>
                                {{ $measure->name }} — about one {{ $measure->scope }}
                            </option>
                        @endforeach
                    </select>
                    <p class="help">A measure is about one altitude. It has to match the board it sits on.</p>
                </div>

                <div x-show="kind === 'report_table'" x-cloak>
                    <label class="label" for="report_definition_id">Report</label>
                    <select id="report_definition_id" name="report_definition_id" class="select">
                        <option value="">Choose a report…</option>
                        @foreach ($reports as $report)
                            <option value="{{ $report->id }}"
                                @selected((int) old('report_definition_id', $widget->report_definition_id) === (int) $report->id)>
                                {{ $report->name }}
                            </option>
                        @endforeach
                    </select>
                    <p class="help">The first few rows only, and always the reader's own rows.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="label" for="title">Heading</label>
                    <input id="title" name="title" type="text" class="input" maxlength="120"
                           value="{{ old('title', $widget->title) }}"
                           placeholder="Leave empty to use the measure’s own name">
                    <p class="help">Your own wording, where the board should say something different from the measure.</p>
                </div>
            </div>
        </div>

        <div class="card mb-5">
            <div class="card-header">
                <p class="card-title">How the figure is obtained</p>
            </div>

            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <div x-show="kind !== 'kpi_trend'" x-cloak>
                    <label class="label" for="refresh">Figure</label>
                    <select id="refresh" name="refresh" class="select">
                        @foreach ($refreshOptions as $key => $label)
                            <option value="{{ $key }}"
                                @selected(old('refresh', $widget->refresh ?? 'live') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="help">
                        Last night’s reading is faster and is the same number for everybody who can see the
                        whole board. Anybody who cannot gets their own figure counted on the spot, and the
                        tile says so.
                    </p>
                </div>

                <div>
                    <label class="label" for="width">Width</label>
                    <select id="width" name="width" class="select">
                        @foreach ($widths as $key => $label)
                            <option value="{{ $key }}"
                                @selected(old('width', $widget->width ?? 'third') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="kind === 'report_table'" x-cloak>
                    <label class="label" for="row_limit">Rows to show</label>
                    <input id="row_limit" name="row_limit" type="number" class="input" min="1" max="{{ $maxRows }}"
                           value="{{ old('row_limit', $widget->row_limit ?? 5) }}">
                    <p class="help">At most {{ $maxRows }}. A dashboard list is a prompt to open the register, not a substitute for it.</p>
                </div>

                <div x-show="kind === 'kpi_trend'" x-cloak>
                    <label class="label" for="span">Readings to draw</label>
                    <input id="span" name="span" type="number" class="input" min="2" max="{{ $maxSpan }}"
                           value="{{ old('span', $widget->span ?? 12) }}">
                    <p class="help">How far back down the line to go. At most {{ $maxSpan }} points.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="label inline-flex items-center gap-2" for="is_active">
                        <input id="is_active" name="is_active" type="checkbox" value="1"
                               @checked(old('is_active', $widget->exists ? $widget->is_active : true))>
                        Show this tile on the board
                    </label>
                    <p class="help">Untick to take it off without losing how it is set up.</p>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
                <x-icon name="check" class="h-3.5 w-3.5"/> {{ $widget->exists ? 'Save tile' : 'Add tile' }}
            </button>
            <a href="{{ route('configuration.widgets.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
        </div>
    </form>

    @if ($widget->exists)
        <form method="POST" action="{{ route('configuration.widgets.destroy', $widget) }}" class="mt-5">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn-danger btn-sm">
                <x-icon name="trash" class="h-3.5 w-3.5"/> Delete this tile
            </button>
            <p class="help mt-1">Hiding it keeps the setup. Deleting does not.</p>
        </form>
    @endif

</x-layouts.app>
