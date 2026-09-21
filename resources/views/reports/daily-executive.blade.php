{{--
    The daily executive report, as it is printed (§55, docs/11 §6).

    Expects: $recipient, $organization, $tiles (RenderedWidget[]), $asOf

    THIS IS A DOMPDF TEMPLATE AND NOT A SCREEN. There is no app layout, no Tailwind and no Alpine
    here — the renderer supplies the page, the margins and the base stylesheet, and everything else
    is laid out with tables and inline styles because that is what Dompdf lays out reliably. Flexbox
    and grid do not survive the trip. Anything added here should be tested by opening the PDF, not
    by reading the markup.

    THE FOOTNOTE ON A TILE IS NOT DECORATION. WidgetComposer sets `shared` to false when a figure was
    counted for this reader alone rather than over the whole scope, and puts the reason in
    `footnote`. A PDF is forwarded — that is most of what a PDF is for — so a figure printed without
    its footnote becomes the company's number the moment it reaches somebody else's inbox. Every
    branch below that prints a value also prints the footnote.
--}}
@php
    /** @var \Illuminate\Support\Collection|array $tiles */
    $figures = array_values(array_filter($tiles, fn ($tile) => $tile->isFigure()));
    $others = array_values(array_filter($tiles, fn ($tile) => ! $tile->isFigure()));

    $tone = [
        'on_target' => ['#15803d', '#f0fdf4'],
        'warning' => ['#b45309', '#fffbeb'],
        'critical' => ['#b91c1c', '#fef2f2'],
        'unknown' => ['#475569', '#f8fafc'],
    ];
@endphp

<h1>{{ $organization?->name ?? 'Daily report' }}</h1>

<p class="muted" style="margin-bottom: 5mm;">
    The executive board as it stood on {{ $asOf->format('l j F Y') }} at {{ $asOf->format('H:i') }}.
    Prepared for {{ $recipient->name }}.
</p>

{{-- Said once, at the top, in the words the dashboard uses. Somebody comparing this with a
     colleague's copy should find the explanation before they find the discrepancy. --}}
<p class="muted" style="margin-bottom: 6mm; padding: 2mm 3mm; background: #f8fafc; border-left: 2pt solid #cbd5e1;">
    Every figure below was counted through your own access. A colleague with a different reach will
    receive different numbers under the same headings from the same report — that is the report
    working, not a fault.
</p>

@if ($figures !== [])
    <table style="border-collapse: separate; border-spacing: 2mm 0; margin-bottom: 4mm;">
        @foreach (array_chunk($figures, 3) as $row)
            <tr>
                @foreach ($row as $tile)
                    @php [$ink, $wash] = $tone[$tile->standing] ?? $tone['unknown']; @endphp
                    <td style="width: 33%; border: 0.5pt solid #e2e8f0; background: {{ $wash }}; padding: 3mm;">
                        <div style="font-size: 8pt; color: #475569;">{{ $tile->heading }}</div>

                        @if ($tile->available)
                            <div style="font-size: 20pt; font-weight: bold; color: {{ $ink }}; margin-top: 1mm;">
                                {{ $tile->value }}
                            </div>
                            @if ($tile->target)
                                <div style="font-size: 7.5pt; color: #64748b;">{{ $tile->target }}</div>
                            @endif
                        @else
                            <div style="font-size: 10pt; color: #64748b; margin-top: 2mm;">
                                {{ $tile->reason ?? 'Could not be counted.' }}
                            </div>
                        @endif

                        @if ($tile->footnote)
                            <div style="font-size: 7pt; color: #64748b; margin-top: 1.5mm;">{{ $tile->footnote }}</div>
                        @endif
                    </td>
                @endforeach

                {{-- Dompdf will stretch three cells' worth of content across two cells otherwise,
                     and the last row of a nine-tile board would be drawn wider than the rest. --}}
                @for ($pad = count($row); $pad < 3; $pad++)
                    <td style="width: 33%;"></td>
                @endfor
            </tr>
        @endforeach
    </table>
@endif

@foreach ($others as $tile)
    <h2>{{ $tile->heading }}</h2>

    @if (! $tile->available)
        <p class="muted">{{ $tile->reason ?? 'Could not be counted.' }}</p>
    @elseif ($tile->isTrend())
        @if ($tile->hasPoints())
            {{-- The line as a table, because a chart drawn in Dompdf is an image this platform
                 would have to generate on a host with no headless browser (§1). The numbers are
                 what somebody reads off a trend anyway; the shape is the dashboard's job. --}}
            <table>
                <thead>
                    <tr>
                        @foreach ($tile->points as $point)
                            <th style="font-size: 7.5pt; text-align: center;">{{ $point['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        @foreach ($tile->points as $point)
                            <td style="text-align: center;">
                                {{ $point['value'] === null ? '—' : rtrim(rtrim(number_format((float) $point['value'], 2), '0'), '.') }}
                            </td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        @else
            <p class="muted">No readings have been recorded for this yet.</p>
        @endif
    @elseif ($tile->isTable())
        @if ($tile->rows === [])
            <p class="muted">Nothing to list.</p>
        @else
            <table>
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
                            @foreach (array_keys($tile->columns) as $field)
                                <td>{{ $row[$field] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @if ($tile->footnote)
        <p class="muted" style="font-size: 7.5pt;">{{ $tile->footnote }}</p>
    @endif
@endforeach

<p class="muted" style="margin-top: 6mm; font-size: 7.5pt;">
    {{ count($tiles) }} {{ \Illuminate\Support\Str::plural('section', count($tiles)) }}.
    To change what appears here, arrange the executive board.
</p>
