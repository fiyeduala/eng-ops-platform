{{--
    The measure builder.

    Expects: $kpi, $fields, $entities, $aggregates, $needsMeasure, $needsNumerator, $operators,
             $operatorsWithoutValue, $periods, $scopes, $directions, $maxFilters

    THERE IS NOWHERE ON THIS PAGE TO TYPE A QUERY, and that is the design rather than an omission.
    Every register, column, comparison and period is chosen from a list the platform offers, because
    a configuration screen that accepted a fragment of SQL would be a remote shell with a nice form
    around it. The selects below are filtered in the browser purely so somebody is not offered
    nonsense; KpiDefinitionService checks every one of them again on the way in (§84), and
    KpiEvaluator checks them a third time when the figure is counted, because a definition outlives
    the register it names.

    The form is one form for both new and existing measures. A builder whose "add" and "edit"
    screens drift apart is how a measure ends up with a field nobody can change afterwards.
--}}
<x-layouts.app :title="$kpi->exists ? 'Edit measure' : 'New measure'">

    @php
        // The whole of the dependent half of the form, handed to Alpine in one object. Built from
        // `old()` first so a refused submission comes back exactly as it was typed — including the
        // filter rows, which are the part somebody has spent real time on.
        $state = [
            'entity_type' => old('entity_type', $kpi->entity_type ?? ''),
            'aggregate' => old('aggregate', $kpi->aggregate ?? 'count'),
            'measure_field' => old('measure_field', $kpi->measure_field ?? ''),
            'period' => old('period', $kpi->period ?? 'all_time'),
            'date_field' => old('date_field', $kpi->date_field ?? ''),
            'scope' => old('scope', $kpi->scope ?? 'organization'),
            'filters' => array_values(old('filters', $kpi->baseFilters() ?: [])),
            'numerator_filters' => array_values(old('numerator_filters', $kpi->numeratorFilters() ?: [])),
        ];
    @endphp

    <x-page-header
        :title="$kpi->exists ? 'Edit “'.$kpi->name.'”' : 'New measure'"
        description="Say which register to count, which records count, and what a good number looks like. No formulas — everything here is chosen from what the platform already knows how to count.">
        <x-slot:actions>
            <a href="{{ route('configuration.kpis.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Measures
            </a>
        </x-slot:actions>
    </x-page-header>

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-red-600"/> That measure was not saved
            </p>
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
          action="{{ $kpi->exists ? route('configuration.kpis.update', $kpi) : route('configuration.kpis.store') }}"
          x-data='{
              catalogue: @json($fields),
              needsMeasure: @json($needsMeasure),
              needsNumerator: @json($needsNumerator),
              withoutValue: @json($operatorsWithoutValue),
              maxFilters: {{ $maxFilters }},
              state: @json($state),

              register() { return this.catalogue[this.state.entity_type] || { filters: {}, measures: {}, dates: {}, scopes: ["organization"] }; },
              measures() { return this.register().measures; },
              dates() { return this.register().dates; },
              filterFields() { return this.register().filters; },
              allowsScope(scope) { return this.register().scopes.includes(scope); },

              wantsMeasure() { return this.needsMeasure.includes(this.state.aggregate); },
              wantsNumerator() { return this.needsNumerator.includes(this.state.aggregate); },
              wantsDate() { return this.state.period !== "all_time"; },
              wantsValue(row) { return row.operator && ! this.withoutValue.includes(row.operator); },

              /*
               * Changing the register empties everything chosen from the old one. Keeping a column
               * name that happens to exist on both would be worse than losing it: the measure would
               * save, and quietly count something nobody meant.
               */
              onRegisterChange() {
                  this.state.measure_field = "";
                  this.state.date_field = "";
                  this.state.filters = [];
                  this.state.numerator_filters = [];
                  if (! this.allowsScope(this.state.scope)) { this.state.scope = "organization"; }
              },

              addFilter(list) {
                  if (this.state[list].length >= this.maxFilters) { return; }
                  this.state[list].push({ field: "", operator: "=", value: "" });
              },
              removeFilter(list, index) { this.state[list].splice(index, 1); },
          }'
          class="space-y-5">
        @csrf
        @if ($kpi->exists)
            @method('PUT')
        @endif

        {{-- ---------------------------------------------------------------- what it is called --}}
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">What it is called</h2>
            </div>

            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label" for="name">Name</label>
                    <input type="text" id="name" name="name" class="input" required maxlength="120"
                           value="{{ old('name', $kpi->name) }}" placeholder="Overdue deliverables">
                    <p class="help">What people will see on a dashboard. Names must be unique — one number per heading.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="label" for="description">What it means</label>
                    <textarea id="description" name="description" class="input" rows="2" maxlength="2000"
                              placeholder="Deliverables whose due date has passed and that are not yet issued.">{{ old('description', $kpi->description) }}</textarea>
                    <p class="help">
                        Write it for whoever reads this figure in two years and wants to know whether it
                        still means what the last person thought it meant.
                    </p>
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- what it counts --}}
        <div class="card">
            <div class="card-header flex-col items-start gap-1">
                <h2 class="card-title">What it counts</h2>
                <p class="text-xs text-[var(--text-muted)]">
                    Only registers the platform can narrow to the person looking are offered here. That is
                    what lets a figure be shown to everybody without showing anybody more than they may see.
                </p>
            </div>

            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="entity_type">Register</label>
                    <select id="entity_type" name="entity_type" class="input" required
                            x-model="state.entity_type" @change="onRegisterChange()">
                        <option value="">Choose a register…</option>
                        @foreach ($entities as $group => $options)
                            <optgroup label="{{ $group }}">
                                @foreach ($options as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="aggregate">Calculation</label>
                    <select id="aggregate" name="aggregate" class="input" required x-model="state.aggregate">
                        @foreach ($aggregates as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="wantsMeasure()" x-cloak>
                    <label class="label" for="measure_field">Which figure</label>
                    <select id="measure_field" name="measure_field" class="input" x-model="state.measure_field">
                        <option value="">Choose a figure…</option>
                        <template x-for="(label, column) in measures()" :key="column">
                            <option :value="column" x-text="label"></option>
                        </template>
                    </select>
                    <p class="help" x-show="Object.keys(measures()).length === 0">
                        That register holds no figures to add up. It can still be counted.
                    </p>
                </div>

                <div>
                    <label class="label" for="scope">Measured at</label>
                    <select id="scope" name="scope" class="input" x-model="state.scope">
                        @foreach ($scopes as $key => $label)
                            <option value="{{ $key }}" x-show="allowsScope('{{ $key }}')">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="help">
                        A department or project measure can be shown on that department's or project's own
                        page. Only the ones this register can actually be cut by are offered.
                    </p>
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- which records --}}
        <div class="card">
            <div class="card-header flex-col items-start gap-1">
                <h2 class="card-title">Which records count</h2>
                <p class="text-xs text-[var(--text-muted)]">
                    Leave this empty to count everything in the register. Conditions can only test
                    structured values — statuses, dates, flags, links to other records — never free text.
                </p>
            </div>

            <div class="space-y-3 p-4">
                <template x-for="(row, index) in state.filters" :key="index">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-[12rem] flex-1">
                            <label class="label">Field</label>
                            <select class="input" :name="'filters[' + index + '][field]'" x-model="row.field">
                                <option value="">Choose…</option>
                                <template x-for="(label, column) in filterFields()" :key="column">
                                    <option :value="column" x-text="label"></option>
                                </template>
                            </select>
                        </div>

                        <div class="min-w-[9rem]">
                            <label class="label">Comparison</label>
                            <select class="input" :name="'filters[' + index + '][operator]'" x-model="row.operator">
                                @foreach ($operators as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="min-w-[10rem] flex-1" x-show="wantsValue(row)" x-cloak>
                            <label class="label">Value</label>
                            <input type="text" class="input" :name="'filters[' + index + '][value]'" x-model="row.value">
                        </div>

                        <button type="button" class="btn btn-secondary btn-sm" @click="removeFilter('filters', index)">
                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                        </button>
                    </div>
                </template>

                <button type="button" class="btn btn-secondary btn-sm" @click="addFilter('filters')"
                        :disabled="state.filters.length >= maxFilters">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a condition
                </button>

                <p class="help" x-show="state.filters.length >= maxFilters" x-cloak>
                    That is the most conditions one measure may carry. A number that takes more than
                    {{ $maxFilters }} to describe is two numbers.
                </p>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- the numerator --}}
        <div class="card" x-show="wantsNumerator()" x-cloak>
            <div class="card-header flex-col items-start gap-1">
                <h2 class="card-title">Which of those are the ones being measured</h2>
                <p class="text-xs text-[var(--text-muted)]">
                    A ratio needs both halves. Everything above is the total; these conditions pick out
                    the part of it you are reporting — passed inspections out of inspections carried out.
                    Without them the answer is always one.
                </p>
            </div>

            <div class="space-y-3 p-4">
                <template x-for="(row, index) in state.numerator_filters" :key="index">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-[12rem] flex-1">
                            <label class="label">Field</label>
                            <select class="input" :name="'numerator_filters[' + index + '][field]'" x-model="row.field">
                                <option value="">Choose…</option>
                                <template x-for="(label, column) in filterFields()" :key="column">
                                    <option :value="column" x-text="label"></option>
                                </template>
                            </select>
                        </div>

                        <div class="min-w-[9rem]">
                            <label class="label">Comparison</label>
                            <select class="input" :name="'numerator_filters[' + index + '][operator]'" x-model="row.operator">
                                @foreach ($operators as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="min-w-[10rem] flex-1" x-show="wantsValue(row)" x-cloak>
                            <label class="label">Value</label>
                            <input type="text" class="input" :name="'numerator_filters[' + index + '][value]'" x-model="row.value">
                        </div>

                        <button type="button" class="btn btn-secondary btn-sm"
                                @click="removeFilter('numerator_filters', index)">
                            <x-icon name="trash" class="h-3.5 w-3.5"/>
                        </button>
                    </div>
                </template>

                <button type="button" class="btn btn-secondary btn-sm" @click="addFilter('numerator_filters')"
                        :disabled="state.numerator_filters.length >= maxFilters">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a condition
                </button>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- the window --}}
        <div class="card">
            <div class="card-header flex-col items-start gap-1">
                <h2 class="card-title">Over what period</h2>
                <p class="text-xs text-[var(--text-muted)]">
                    "All time" counts everything in the register. A period counts only records whose
                    chosen date falls inside it — and the period moves, so one measure answers about
                    this month, last month and the month before without being defined three times.
                </p>
            </div>

            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="period">Period</label>
                    <select id="period" name="period" class="input" x-model="state.period">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div x-show="wantsDate()" x-cloak>
                    <label class="label" for="date_field">Counting by which date</label>
                    <select id="date_field" name="date_field" class="input" x-model="state.date_field">
                        <option value="">Choose a date…</option>
                        <template x-for="(label, column) in dates()" :key="column">
                            <option :value="column" x-text="label"></option>
                        </template>
                    </select>
                    <p class="help">
                        "Raised this month" and "closed this month" are different measures of the same
                        register, and the only thing that distinguishes them is this box.
                    </p>
                </div>
            </div>
        </div>

        {{-- ---------------------------------------------------------------- what good looks like --}}
        <div class="card">
            <div class="card-header flex-col items-start gap-1">
                <h2 class="card-title">What a good number looks like</h2>
                <p class="text-xs text-[var(--text-muted)]">
                    Optional, and entirely yours to set. The platform has no opinion about whether bigger
                    is better — it is on a safety measure and it is not on an incident count.
                </p>
            </div>

            <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="label" for="target">Target</label>
                    <input type="number" step="any" id="target" name="target" class="input"
                           value="{{ old('target', $kpi->target) }}">
                </div>

                <div>
                    <label class="label" for="unit">Unit</label>
                    <input type="text" id="unit" name="unit" class="input" maxlength="24"
                           value="{{ old('unit', $kpi->unit) }}" placeholder="hours, %, NGN">
                </div>

                <div>
                    <label class="label" for="direction">Which way is good</label>
                    <select id="direction" name="direction" class="input">
                        @foreach ($directions as $key => $label)
                            <option value="{{ $key }}" @selected(old('direction', $kpi->direction) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="label" for="sort">Order on the dashboard</label>
                    <input type="number" id="sort" name="sort" class="input" value="{{ old('sort', $kpi->sort ?? 0) }}">
                </div>

                @php $thresholds = $kpi->thresholdValues(); @endphp

                <div>
                    <label class="label" for="threshold_warning">Amber at</label>
                    <input type="number" step="any" id="threshold_warning" name="thresholds[warning]" class="input"
                           value="{{ old('thresholds.warning', $thresholds['warning']) }}">
                </div>

                <div>
                    <label class="label" for="threshold_critical">Red at</label>
                    <input type="number" step="any" id="threshold_critical" name="thresholds[critical]" class="input"
                           value="{{ old('thresholds.critical', $thresholds['critical']) }}">
                </div>

                <div class="sm:col-span-2 lg:col-span-2 flex items-end">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" value="1"
                               @checked(old('is_active', $kpi->exists ? $kpi->is_active : true))>
                        Measure this now
                    </label>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('configuration.kpis.index') }}" class="btn btn-secondary btn-sm">Cancel</a>
            <button type="submit" class="btn btn-primary btn-sm">
                {{ $kpi->exists ? 'Save changes' : 'Create measure' }}
            </button>
        </div>
    </form>

</x-layouts.app>
