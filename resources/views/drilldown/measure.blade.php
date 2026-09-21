{{--
    The records behind one figure (§89).

    Expects: $kpi, $result, $columns, $records (paginator or null), $scopeLabel, $entityLabel

    THE HEADLINE NUMBER AND THE ROW COUNT ARE THE SAME NUMBER, and that is worth saying on the page
    rather than only in the code: both come from one builder in KpiEvaluator, so a reader who counts
    the rows and gets a different answer has found a bug rather than a rounding difference. For a
    measure that adds up or averages a column the figure is not a row count at all, so the page says
    how many records it was worked out from instead of implying the two match.
--}}
<x-layouts.app title="{{ $kpi->name }}">

    <x-page-header
        :title="$kpi->name"
        :description="$scopeLabel ? $entityLabel.' · '.$scopeLabel : $entityLabel">
        <x-slot:actions>
            {{--
                Back to the landing page rather than to `url()->previous()`. The referer is a
                header somebody else can set, and a "Back" button that follows it is a link to
                anywhere — a small thing, but this page is reached from a dashboard and the
                dashboard is always a correct answer.
            --}}
            <a href="{{ route('dashboard') }}" class="btn btn-sm btn-ghost">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Dashboard
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-6">
        <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2 p-5">
            <span class="text-3xl font-semibold tracking-tight text-[var(--text-strong)]">
                {{ $result->display($kpi) }}
            </span>

            @if ($result->available)
                <span class="text-sm text-[var(--text-muted)]">
                    {{ $kpi->aggregate === 'count'
                        ? 'Counted through what you can see.'
                        : 'Worked out from '.number_format($result->population).' '.\Illuminate\Support\Str::plural('record', $result->population).' you can see.' }}
                </span>
            @else
                <span class="text-sm text-[var(--text-muted)]">{{ $result->reason }}</span>
            @endif
        </div>
    </div>

    @if ($records === null)
        <x-empty-state
            icon="alert"
            title="This measure cannot be listed"
            :description="$result->reason ?? 'The register behind it is no longer offered, so there is nothing to show.'"/>
    @elseif ($records->total() === 0)
        <x-empty-state
            icon="empty"
            title="Nothing to list"
            description="No record you can see falls inside this measure. Somebody with wider access may see more."/>
    @else
        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            @foreach ($columns as $heading)
                                <th>{{ $heading }}</th>
                            @endforeach
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($records as $record)
                            @php
                                // Asked per row rather than once for the register: RecordLinks
                                // answers null for anything with no page of its own, and a record
                                // that was hard-deleted mid-listing should degrade to plain text
                                // rather than produce a link to nowhere.
                                $href = \App\Support\RecordLinks::for($record);
                            @endphp
                            <tr>
                                @foreach ($columns as $column => $heading)
                                    <td>{{ \App\Support\RecordPresenter::cell($kpi->entity_type, $record, $column) }}</td>
                                @endforeach
                                <td class="text-right">
                                    @if ($href)
                                        <a href="{{ $href }}" class="text-xs font-medium text-brand-600 hover:underline">Open</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($records->hasPages())
                <div class="p-4">{{ $records->links() }}</div>
            @endif
        </div>
    @endif

</x-layouts.app>
