@props(['tile'])

{{--
    One tile, already decided.

    Expects: $tile — an App\Engines\Dashboard\RenderedWidget.

    THIS FILE MAKES NO DECISIONS ABOUT VISIBILITY, and that is the single rule it has to keep.
    WidgetComposer has already established whether this reader may see the tile at all, whether the
    figure is the scope's or their own, and what sentence explains the difference. Anything here
    that looked at the viewer would be a second, weaker copy of that logic in a template — and the
    template is the layer nobody writes a test for.

    THE FOOTNOTE IS NOT DECORATION. It is the sentence that stops two colleagues comparing screens
    and concluding the platform is broken: one of them is being shown the whole company's figure
    because their reach covers it, the other their own because it does not. Dropping it to tidy up
    the layout would leave the discrepancy with no explanation attached to it.
--}}

@php
    $span = match ($tile->width) {
        'quarter' => 'lg:col-span-3',
        'half' => 'lg:col-span-6',
        'full' => 'lg:col-span-12',
        default => 'lg:col-span-4',
    };

    $tone = match ($tile->standing) {
        'critical' => 'text-red-600',
        'warning' => 'text-amber-600',
        'on_target' => 'text-emerald-600',
        default => 'text-[var(--text-strong)]',
    };
@endphp

<div class="card col-span-12 flex flex-col p-4 {{ $span }}">

    <div class="flex items-start justify-between gap-2">
        <p class="min-w-0 truncate text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
            {{ $tile->heading }}
        </p>
        @if ($tile->shared)
            {{-- Said plainly rather than hidden, because "this is the company's number, not yours"
                 is exactly what a reader needs to know before quoting it in a meeting. --}}
            <x-badge tone="brand">Shared</x-badge>
        @endif
    </div>

    @if (! $tile->available)
        <p class="mt-3 text-2xl font-semibold text-[var(--text-muted)]">—</p>
        <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $tile->reason }}</p>

    @elseif ($tile->isFigure())
        <p class="mt-3 text-3xl font-semibold {{ $tone }}">{{ $tile->value }}</p>
        @if ($tile->target)
            <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $tile->target }}</p>
        @endif

    @elseif ($tile->isTrend())
        @php
            [$low, $high] = $tile->bounds();
            $range = $high - $low;
            $count = max(1, count($tile->points) - 1);
            $plotted = [];

            foreach ($tile->points as $index => $point) {
                if ($point['value'] === null) {
                    continue;
                }

                // A flat line is drawn flat rather than dividing by zero, and a single point is
                // drawn in the middle rather than at the bottom, where it would read as a low.
                $y = $range > 0 ? 40 - (($point['value'] - $low) / $range) * 34 : 22;
                $plotted[] = round($index / $count * 100, 2).','.round($y, 2);
            }
        @endphp

        <p class="mt-3 text-2xl font-semibold {{ $tone }}">{{ $tile->value }}</p>

        @if (count($plotted) > 1)
            <svg viewBox="0 0 100 46" preserveAspectRatio="none" class="mt-2 h-14 w-full" aria-hidden="true">
                <polyline points="{{ implode(' ', $plotted) }}" fill="none"
                          stroke="currentColor" stroke-width="1.5"
                          class="text-emerald-600" vector-effect="non-scaling-stroke"/>
            </svg>
            <p class="text-xs text-[var(--text-muted)]">
                {{ $tile->points[0]['label'] }} — {{ $tile->points[count($tile->points) - 1]['label'] }}
            </p>
        @else
            <p class="mt-1 text-xs text-[var(--text-muted)]">
                Not enough readings yet to draw a line.
            </p>
        @endif

    @elseif ($tile->isTable())
        @if ($tile->rows === [])
            <p class="mt-3 text-sm text-[var(--text-muted)]">Nothing to show.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="table text-xs">
                    <thead>
                        <tr>
                            @foreach ($tile->columns as $heading)
                                <th>{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tile->rows as $row)
                            <tr>
                                @foreach ($tile->columns as $field => $heading)
                                    <td>{{ $row[$field] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    @if ($tile->footnote)
        <p class="mt-2 text-xs text-[var(--text-muted)]">{{ $tile->footnote }}</p>
    @endif

    {{--
        The drill-down (§89). A figure leads to the records it counted; a list tile leads to the
        report it is the top of. Worded differently because they are different promises, and a
        reader who clicks "Open" on a number and lands on a report builder has been misled.
    --}}
    @if ($tile->href)
        <a href="{{ $tile->href }}" class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-[var(--text-strong)] hover:underline">
            {{ $tile->isFigure() ? 'See the records' : 'Open the report' }}
            <x-icon name="chevron-right" class="h-3 w-3"/>
        </a>
    @endif

</div>
