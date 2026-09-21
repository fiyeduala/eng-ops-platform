{{--
    One report, run for whoever opened it.

    Expects: $report, $result, $adHoc, $filterFields, $operators, $operatorsWithoutValue,
             $entities, $canExport, $canEdit, $savedViews, $visibilities, $canShareWidely

    THE BANNER SAYING THESE ARE YOUR ROWS IS NOT DECORATION. A report looks authoritative in a way a
    dashboard tile does not — it has columns and a row count and it prints — so somebody will quote
    the number at the bottom in a meeting. If two people quote two different numbers and neither page
    said why, the conclusion drawn is that the platform is unreliable rather than that it is working.
--}}
<x-layouts.app title="{{ $report->name }}">

    <x-page-header
        title="{{ $report->name }}"
        description="{{ $report->description ?: 'Listing '.\Illuminate\Support\Str::lower($entities[$report->entity_type] ?? $report->entity_type).'.' }}">
        <x-slot:actions>
            @if ($canExport && $result->available && ! $result->isEmpty())
                @php
                    // Built here rather than inline, so the ad-hoc conditions on screen travel with
                    // the export and the file matches the page. Without this an export would
                    // silently return the unfiltered report — the same rows, minus the narrowing
                    // the person just applied, with nothing to say so.
                    $exportQuery = request()->has('filters') ? ['filters' => request()->input('filters')] : [];

                    // Short names on the buttons and the full sentence in the tooltip. The constant's
                    // labels say "CSV (spreadsheet)" and "Excel workbook" because they are read in a
                    // settings list; four of those side by side in a page header is a wrapped row of
                    // prose where four file types should be.
                    $exportButtons = ['csv' => 'CSV', 'xlsx' => 'Excel', 'docx' => 'Word', 'pdf' => 'PDF'];
                @endphp

                @foreach (\App\Models\ReportDefinition::FORMATS as $exportFormat => $exportLabel)
                    <a href="{{ route('configuration.reports.export', ['report' => $report, 'format' => $exportFormat] + $exportQuery) }}"
                       title="{{ $exportLabel }}"
                       class="btn btn-secondary btn-sm">
                        <x-icon name="download" class="h-3.5 w-3.5"/> {{ $exportButtons[$exportFormat] ?? \Illuminate\Support\Str::upper($exportFormat) }}
                    </a>
                @endforeach
            @endif

            @if ($canEdit)
                <a href="{{ route('configuration.reports.edit', $report) }}" class="btn btn-ghost btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            <a href="{{ route('configuration.reports.index') }}" class="btn btn-secondary btn-sm">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Reports
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @if ($errors->any())
        <div class="card mb-5 border-red-300 p-4 text-sm">
            <ul class="ml-6 list-disc space-y-1 text-[var(--text-body)]">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-5 flex items-start gap-2 p-4 text-sm text-[var(--text-body)]">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
        <p>
            These are the records <span class="font-medium">you</span> are allowed to see. A colleague
            running the same report may get more rows, or fewer. Anything you export carries the same
            limit, and says so on the file.
        </p>
    </div>

    {{-- The ad-hoc filter bar. Only the structured fields the register offers appear here: there is
         deliberately no free-text search box, because a condition somebody can retype is a way to
         read content back one guess at a time, with the row count as the oracle (§61). --}}
    @if ($filterFields !== [])
        <form method="GET" action="{{ route('configuration.reports.show', $report) }}" class="card mb-5 p-4">
            <p class="label mb-2">Narrow this run</p>

            @php
                // One blank row if nothing is applied, so the bar is usable on first open; otherwise
                // the applied ones plus one spare.
                $rows = $adHoc === [] ? [['field' => '', 'operator' => '=', 'value' => '']] : $adHoc;
                $rows[] = ['field' => '', 'operator' => '=', 'value' => ''];
            @endphp

            <div class="space-y-2">
                @foreach ($rows as $index => $row)
                    <div class="flex flex-wrap items-center gap-2">
                        <select name="filters[{{ $index }}][field]" class="select w-56">
                            <option value="">— no condition —</option>
                            @foreach ($filterFields as $field => $label)
                                <option value="{{ $field }}" @selected(($row['field'] ?? '') === $field)>{{ $label }}</option>
                            @endforeach
                        </select>

                        <select name="filters[{{ $index }}][operator]" class="select w-44">
                            @foreach ($operators as $key => $label)
                                <option value="{{ $key }}" @selected(($row['operator'] ?? '') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>

                        <input type="text" name="filters[{{ $index }}][value]" class="input w-56"
                               value="{{ is_array($row['value'] ?? null) ? implode(', ', $row['value']) : ($row['value'] ?? '') }}"
                               placeholder="value">
                    </div>
                @endforeach
            </div>

            <div class="mt-3 flex items-center gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <x-icon name="filter" class="h-3.5 w-3.5"/> Apply
                </button>

                @if ($adHoc !== [])
                    <a href="{{ route('configuration.reports.show', $report) }}" class="btn btn-ghost btn-sm">
                        Clear
                    </a>
                @endif

                <span class="help">
                    Conditions added here apply to this run only. They are not saved to the report,
                    and they travel with anything you export.
                </span>
            </div>
        </form>
    @endif

    {{-- Saved views (§54, docs/11 §2).

         WHAT IS SAVED IS THE QUESTION, NEVER THE ANSWER, and the line under the heading says so on
         the screen rather than only in the docblock. Somebody sharing a view with their department
         is entitled to know what the other person will get: the same conditions, run against their
         own access, which may come back empty. A feature that looked like "send them this list"
         and silently behaved like "send them a shorter list" would be read as a fault.

         Applying one is a link, not a form. It redirects to this same screen with the conditions in
         the query string — so a saved view and a typed one end up as the identical request, and
         there is no second path the rows could come back through. --}}
    @if ($filterFields !== [])
        <div class="card mb-5 p-4">
            <p class="label mb-1">Saved views</p>
            <p class="help mb-3">
                A saved view keeps the conditions, not the rows. Anyone you share it with runs it
                against their own access and may see a different list.
            </p>

            @if ($savedViews->isEmpty())
                <p class="mb-3 text-sm text-[var(--text-muted)]">
                    Nothing saved yet for {{ \Illuminate\Support\Str::lower($entities[$report->entity_type] ?? $report->entity_type) }}.
                </p>
            @else
                <ul class="mb-4 space-y-2">
                    @foreach ($savedViews as $view)
                        <li class="flex flex-wrap items-center gap-2 border-b border-[var(--surface-border)] pb-2 last:border-0">
                            <a href="{{ route('configuration.reports.views.apply', ['report' => $report, 'savedFilter' => $view]) }}"
                               class="btn btn-secondary btn-sm">
                                <x-icon name="filter" class="h-3.5 w-3.5"/> {{ $view->name }}
                            </a>

                            <x-badge tone="neutral">{{ $view->visibilityLabel() }}</x-badge>

                            @if ($view->conditions() === [])
                                {{-- Marked rather than hidden. Applying it is refused anyway, but a
                                     name that quietly stopped narrowing anything is the one failure
                                     here nobody would notice, so it says so before it is clicked. --}}
                                <x-badge tone="warning">No longer works</x-badge>
                            @endif

                            @unless ($view->ownedBy(auth()->user()))
                                {{-- Whose view it is, because a name on your screen that you did not
                                     write is otherwise a mystery you cannot ask anyone about. --}}
                                <span class="help">shared by {{ $view->owner?->name ?? 'a colleague' }}</span>
                            @endunless

                            @if ($view->ownedBy(auth()->user()))
                                <form method="POST" class="flex items-center gap-2"
                                      action="{{ route('configuration.reports.views.update', ['report' => $report, 'savedFilter' => $view]) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="name" value="{{ $view->name }}">

                                    <select name="visibility" class="select w-52 text-sm">
                                        @foreach ($visibilities as $key => $label)
                                            @continue($key === 'organization' && ! $canShareWidely)
                                            <option value="{{ $key }}" @selected($view->visibility === $key)>{{ $label }}</option>
                                        @endforeach
                                    </select>

                                    <button type="submit" class="btn btn-ghost btn-sm">Change</button>
                                </form>
                            @endif

                            @can('delete', $view)
                                <form method="POST"
                                      action="{{ route('configuration.reports.views.destroy', ['report' => $report, 'savedFilter' => $view]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-ghost btn-sm text-red-600">
                                        <x-icon name="trash" class="h-3.5 w-3.5"/> Remove
                                    </button>
                                </form>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($adHoc !== [])
                {{-- Offered only when there is something to save. A "save this view" box above an
                     unfiltered report saves the whole register under a name, which is a bookmark
                     for nothing and a name on other people's screens for less. --}}
                <form method="POST" class="flex flex-wrap items-end gap-2"
                      action="{{ route('configuration.reports.views.store', $report) }}">
                    @csrf

                    @foreach ($adHoc as $index => $condition)
                        {{-- The conditions currently on screen, posted back. The service checks every
                             one of them against the register again; this is the shape, not the
                             authority (§84). --}}
                        <input type="hidden" name="filters[{{ $index }}][field]" value="{{ $condition['field'] }}">
                        <input type="hidden" name="filters[{{ $index }}][operator]" value="{{ $condition['operator'] }}">
                        <input type="hidden" name="filters[{{ $index }}][value]"
                               value="{{ is_array($condition['value'] ?? null) ? implode(',', $condition['value']) : ($condition['value'] ?? '') }}">
                    @endforeach

                    <div>
                        <label class="label" for="saved-view-name">Save these conditions as</label>
                        <input type="text" id="saved-view-name" name="name" class="input w-64"
                               value="{{ old('name') }}" maxlength="120" placeholder="Open in Civil" required>
                    </div>

                    <div>
                        <label class="label" for="saved-view-visibility">Who can see it</label>
                        <select id="saved-view-visibility" name="visibility" class="select w-52">
                            @foreach ($visibilities as $key => $label)
                                @continue($key === 'organization' && ! $canShareWidely)
                                <option value="{{ $key }}" @selected(old('visibility') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="check" class="h-3.5 w-3.5"/> Save view
                    </button>
                </form>
            @endif
        </div>
    @endif

    @if (! $result->available)
        <div class="card p-6 text-sm">
            <p class="mb-1 flex items-center gap-2 font-medium text-[var(--text-strong)]">
                <x-icon name="alert" class="h-4 w-4 text-amber-600"/> This report could not be run
            </p>
            {{-- The reason, printed. An empty table and a broken report look identical otherwise,
                 and they are opposite pieces of news. --}}
            <p class="text-[var(--text-body)]">{{ $result->reason }}</p>
        </div>
    @elseif ($result->isEmpty())
        <div class="card p-6 text-sm text-[var(--text-muted)]">
            <p class="mb-2 font-medium text-[var(--text-strong)]">Nothing matched.</p>
            <p class="max-w-2xl">
                Either there are no records like this, or none that you are permitted to see. If a
                colleague sees rows here and you do not, that is the difference between your two
                levels of access rather than a fault.
            </p>
        </div>
    @else
        @if ($result->truncationNotice())
            <div class="card mb-5 border-amber-300 p-4 text-sm">
                <p class="flex items-start gap-2 text-[var(--text-body)]">
                    <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
                    <span>{{ $result->truncationNotice() }}</span>
                </p>
            </div>
        @endif

        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        @if ($result->isGrouped())
                            <th>Group</th>
                        @endif
                        @foreach ($result->columns as $heading)
                            <th>{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result->groups as $group)
                        @foreach ($group['rows'] as $position => $row)
                            <tr>
                                @if ($result->isGrouped())
                                    <td class="text-sm text-[var(--text-muted)]">
                                        {{-- Printed once per group rather than on every row: on
                                             screen the eye follows the gap, which a repeated label
                                             would fill. The CSV does the opposite, and says why. --}}
                                        {{ $position === 0 ? ($group['label'] ?? '') : '' }}
                                    </td>
                                @endif

                                @foreach (array_keys($result->columns) as $column)
                                    <td class="text-sm text-[var(--text-body)]">{{ $row[$column] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="help mt-3">
            {{ number_format($result->total) }}
            {{ \Illuminate\Support\Str::plural('row', $result->total) }}, as you can see them.
            Run {{ $result->ranAt?->format('j M Y, H:i') }}.
        </p>
    @endif

</x-layouts.app>
