{{--
    The report builder.

    Expects: $report, $fields, $entities, $operators, $operatorsWithoutValue, $directions,
             $maxFilters, $maxColumns, $maxRows

    THERE IS NOWHERE ON THIS PAGE TO TYPE A QUERY. The same rule the measure builder states, for the
    same reason: a configuration screen that accepted a fragment of SQL would be a remote shell with
    a nice form around it. Every register, column, condition, grouping and sort is chosen from a list
    the platform offers.

    THE COLUMN LIST AND THE CONDITION LIST ARE DIFFERENT LISTS, and on this screen that is visible
    rather than merely true. A document's title can be ticked as a column and does not appear in the
    condition dropdown at all. That is not an oversight to be tidied up later — showing somebody
    forty titles they are entitled to is not the same as letting them ask forty thousand yes/no
    questions about titles they are not (§61). ReportCatalogue argues it at length.

    One form for both new and existing reports, so the two cannot drift into offering different
    fields.
--}}
<x-layouts.app :title="$report->exists ? 'Edit report' : 'New report'">

    @php
        // The dependent half of the form in one object, built from `old()` first so a refused
        // submission comes back exactly as it was typed — including the column ticks and the
        // condition rows, which are the parts somebody has spent real time on.
        $state = [
            'entity_type' => old('entity_type', $report->entity_type ?? ''),
            'columns' => array_values(old('columns', $report->selectedColumns() ?: [])),
            'filters' => array_values(old('filters', $report->baseFilters() ?: [])),
            'group_field' => old('group_field', $report->group_field ?? ''),
            'sort_field' => old('sort_field', $report->sort_field ?? ''),
            'sort_direction' => old('sort_direction', $report->sort_direction ?? 'desc'),
        ];
    @endphp

    <x-page-header
        :title="$report->exists ? 'Edit “'.$report->name.'”' : 'New report'"
        description="Say which register to list, which columns to print, and which records belong in it. Everything here is chosen from what the platform already knows how to list.">
        <x-slot:actions>
            <a href="{{ route('configuration.reports.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Reports
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That report was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $report->exists ? route('configuration.reports.update', $report) : route('configuration.reports.store') }}"
          x-data='{
              catalogue: @json($fields),
              withoutValue: @json($operatorsWithoutValue),
              maxFilters: {{ $maxFilters }},
              maxColumns: {{ $maxColumns }},
              state: @json($state),

              register() { return this.catalogue[this.state.entity_type] || { columns: {}, filters: {}, groups: {}, sorts: {} }; },
              columnOptions() { return this.register().columns; },
              filterFields() { return this.register().filters; },
              groupOptions() { return this.register().groups; },
              sortOptions() { return this.register().sorts; },

              hasColumn(key) { return this.state.columns.includes(key); },
              wantsValue(row) { return row.operator && ! this.withoutValue.includes(row.operator); },

              /*
               * Ticking a column appends it, so the order the boxes were ticked in is the order the
               * columns print in. A report is read left to right, and alphabetical-by-accident is
               * not a reading order anybody chose.
               */
              toggleColumn(key) {
                  const at = this.state.columns.indexOf(key);
                  if (at >= 0) { this.state.columns.splice(at, 1); return; }
                  if (this.state.columns.length >= this.maxColumns) { return; }
                  this.state.columns.push(key);
              },

              /*
               * Changing the register empties everything chosen from the old one. Keeping a column
               * that happens to exist on both registers would be worse than losing it: the report
               * would save, and quietly list something nobody meant.
               */
              onRegisterChange() {
                  this.state.columns = [];
                  this.state.filters = [];
                  this.state.group_field = "";
                  this.state.sort_field = "";
              },

              addFilter() {
                  if (this.state.filters.length >= this.maxFilters) { return; }
                  this.state.filters.push({ field: "", operator: "=", value: "" });
              },

              removeFilter(index) { this.state.filters.splice(index, 1); },
          }'>
        @csrf
        @if ($report->exists)
            @method('PUT')
        @endif

        <div class="space-y-5">

            {{-- ---------------------------------------------------------- what it is called --}}
            <div class="card p-5">
                <p class="card-title mb-4">What this report is called</p>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="name" class="label">Name</label>
                        <input type="text" id="name" name="name" class="input" maxlength="120" required
                               value="{{ old('name', $report->name ?? '') }}">
                        <p class="help">The company's own words. It heads every export.</p>
                    </div>

                    <div>
                        <label for="entity_type" class="label">Lists</label>
                        <select id="entity_type" name="entity_type" class="select" required
                                x-model="state.entity_type" @change="onRegisterChange()">
                            <option value="">— choose a register —</option>
                            @foreach ($entities as $group => $options)
                                <optgroup label="{{ $group }}">
                                    @foreach ($options as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="help">Changing this clears the columns and conditions below.</p>
                    </div>
                </div>

                <div class="mt-4">
                    <label for="description" class="label">What it is for</label>
                    <textarea id="description" name="description" class="input" rows="2"
                              maxlength="2000">{{ old('description', $report->description ?? '') }}</textarea>
                    <p class="help">
                        Optional, and worth writing. The person reading this in two years is rarely
                        the person who built it, and “outstanding RFIs” does not say whether ones
                        answered but not accepted are in or out.
                    </p>
                </div>
            </div>

            {{-- ---------------------------------------------------------- columns --}}
            <div class="card p-5" x-show="state.entity_type" x-cloak>
                <p class="card-title mb-1">What it prints</p>
                <p class="help mb-4">
                    Up to {{ $maxColumns }} columns, printed in the order you tick them.
                </p>

                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <template x-for="(label, key) in columnOptions()" :key="key">
                        <label class="flex items-center gap-2 text-sm text-[var(--text-body)]">
                            <input type="checkbox" :value="key" :checked="hasColumn(key)"
                                   @change="toggleColumn(key)">
                            <span x-text="label"></span>
                        </label>
                    </template>
                </div>

                {{-- The ticks are mirrored into hidden inputs in the chosen order, because a
                     checkbox list posts in DOM order and the order is what the report prints in. --}}
                <template x-for="(key, index) in state.columns" :key="key">
                    <input type="hidden" name="columns[]" :value="key">
                </template>

                <p class="help mt-3" x-show="state.columns.length >= maxColumns" x-cloak>
                    That is the most a report can print. Past {{ $maxColumns }} columns it is a
                    spreadsheet somebody exports and never opens.
                </p>
            </div>

            {{-- ---------------------------------------------------------- conditions --}}
            <div class="card p-5" x-show="state.entity_type" x-cloak>
                <p class="card-title mb-1">Which records belong in it</p>
                <p class="help mb-4">
                    Leave this empty to list everything. Conditions can only be built on structured
                    fields — a status, a category, a date, a yes/no — never on free text.
                </p>

                <div class="space-y-2">
                    <template x-for="(row, index) in state.filters" :key="index">
                        <div class="flex flex-wrap items-center gap-2">
                            <select :name="'filters[' + index + '][field]'" class="select w-56"
                                    x-model="row.field">
                                <option value="">— choose a field —</option>
                                <template x-for="(label, key) in filterFields()" :key="key">
                                    <option :value="key" x-text="label"></option>
                                </template>
                            </select>

                            <select :name="'filters[' + index + '][operator]'" class="select w-44"
                                    x-model="row.operator">
                                @foreach ($operators as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            <input type="text" :name="'filters[' + index + '][value]'" class="input w-56"
                                   x-model="row.value" x-show="wantsValue(row)" placeholder="value">

                            <button type="button" class="btn btn-ghost btn-sm" @click="removeFilter(index)">
                                <x-icon name="trash" class="h-3.5 w-3.5"/>
                            </button>
                        </div>
                    </template>
                </div>

                <button type="button" class="btn btn-secondary btn-sm mt-3" @click="addFilter()"
                        x-show="state.filters.length < maxFilters">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a condition
                </button>
            </div>

            {{-- ---------------------------------------------------------- shape --}}
            <div class="card p-5" x-show="state.entity_type" x-cloak>
                <p class="card-title mb-4">How it is arranged</p>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="group_field" class="label">Group by</label>
                        <select id="group_field" name="group_field" class="select" x-model="state.group_field">
                            <option value="">— a flat list —</option>
                            <template x-for="(label, key) in groupOptions()" :key="key">
                                <option :value="key" x-text="label"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label for="sort_field" class="label">Order by</label>
                        <select id="sort_field" name="sort_field" class="select" x-model="state.sort_field">
                            <option value="">— newest first —</option>
                            <template x-for="(label, key) in sortOptions()" :key="key">
                                <option :value="key" x-text="label"></option>
                            </template>
                        </select>
                    </div>

                    <div>
                        <label for="sort_direction" class="label">Direction</label>
                        <select id="sort_direction" name="sort_direction" class="select"
                                x-model="state.sort_direction">
                            @foreach ($directions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="row_limit" class="label">Most rows to return</label>
                        <input type="number" id="row_limit" name="row_limit" class="input"
                               min="1" max="{{ $maxRows }}"
                               value="{{ old('row_limit', $report->row_limit ?: 500) }}">
                        <p class="help">
                            At most {{ number_format($maxRows) }}. A report that stops short says so
                            on the page and on anything exported from it.
                        </p>
                    </div>

                    <div>
                        <label for="sort" class="label">Position in the list</label>
                        <input type="number" id="sort" name="sort" class="input"
                               value="{{ old('sort', $report->sort ?? 0) }}">
                        <p class="help">Lower numbers come first.</p>
                    </div>
                </div>

                <label class="mt-4 flex items-center gap-2 text-sm text-[var(--text-body)]">
                    <input type="checkbox" name="is_active" value="1"
                           @checked(old('is_active', $report->is_active ?? true))>
                    On the reports list
                </label>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn btn-primary">
                    {{ $report->exists ? 'Save changes' : 'Build it' }}
                </button>
                <a href="{{ route('configuration.reports.index') }}" class="btn btn-ghost">Cancel</a>
            </div>
        </div>
    </form>

</x-layouts.app>
