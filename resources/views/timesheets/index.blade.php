<x-layouts.app title="Timesheets">
    <x-page-header title="Timesheets" description="One sheet a week: hours per project and day. Your line manager approves it in My Approvals."/>
    @include('finance.errors')

    @if ($canCreate)
        <div class="card mb-6 p-5">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="card-title">This week</h2>
                    @if ($current)
                        <p class="mt-1 text-sm">
                            <a class="font-mono font-medium hover:text-brand-600" href="{{ route('timesheets.show', $current) }}">{{ $current->number }}</a>
                            · {{ rtrim(rtrim((string) $current->total_hours, '0'), '.') ?: '0' }}h
                            · @if ($current->status)<x-badge :tone="$current->status->tone()">{{ $current->status->label }}</x-badge>@endif
                        </p>
                    @else
                        <p class="mt-1 text-sm text-[var(--text-muted)]">No sheet yet for the week of {{ $thisWeek->format('j M Y') }}.</p>
                    @endif
                </div>
                <form method="POST" action="{{ route('timesheets.store') }}" class="flex flex-wrap items-end gap-2">
                    @csrf
                    @if ($canChooseEmployee)
                        <x-field label="Person" name="employee_id"><x-select name="employee_id" :options="$employees" :selected="old('employee_id')" placeholder="Me"/></x-field>
                    @endif
                    <x-field label="Week containing" name="week"><x-input type="date" name="week" :value="old('week', $thisWeek->toDateString())"/></x-field>
                    <button class="btn btn-primary">Open week</button>
                </form>
            </div>
        </div>
    @endif

    <div class="mb-4 flex gap-2 text-sm">
        <a href="{{ route('timesheets.index', ['view' => 'mine']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-secondary' : 'btn-ghost' }}">My timesheets</a>
        <a href="{{ route('timesheets.index', ['view' => 'all']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-ghost' : 'btn-secondary' }}">All I can see</a>
    </div>
    <x-register.filters route="timesheets.index" :filters="$filters" :statuses="$statuses" placeholder="Number"/>
    <x-register.table :records="$sheets" icon="clipboard" title="No timesheets" description="No timesheet within your scope matches these filters.">
        <x-slot:head><tr><th>Sheet</th><th>Person</th><th>Week</th><th class="text-right">Hours</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($sheets as $sheet)
                <tr>
                    <td><a class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('timesheets.show', $sheet) }}">{{ $sheet->number }}</a></td>
                    <td>{{ $sheet->employee?->fullName() ?? '—' }}</td>
                    <td class="whitespace-nowrap">{{ $sheet->periodLabel() }}</td>
                    <td class="text-right font-mono">{{ rtrim(rtrim((string) $sheet->total_hours, '0'), '.') ?: '0' }}</td>
                    <td>@if ($sheet->status)<x-badge :tone="$sheet->status->tone()">{{ $sheet->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
