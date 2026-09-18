<x-layouts.app title="Payroll">
    <x-page-header title="Payroll runs" description="One row per period. Figures are produced by calculating a run, never typed into one.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('payroll-runs.create') }}" class="btn btn-primary btn-sm">Open a run</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'In progress', 'all' => 'All runs'] as $key => $label)
            <a href="{{ route('payroll-runs.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <x-register.filters route="payroll-runs.index" :filters="$filters" :statuses="$statuses" placeholder="Run number or name"/>

    <x-register.table :records="$runs" icon="banknotes" title="No payroll runs"
                      description="Nothing to show yet. A run is opened for a period, calculated, then sent for approval like any other record on the platform.">
        <x-slot:head>
            <tr><th>Number</th><th>Period</th><th>Pay date</th><th class="text-right">Payslips</th><th class="text-right">Net</th><th>Status</th></tr>
        </x-slot:head>
        <x-slot:rows>
            @foreach ($runs as $row)
                <tr>
                    <td>
                        <a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('payroll-runs.show', $row) }}">{{ $row->number }}</a>
                        @if ($row->isCorrection())
                            {{-- Said on the register, not buried on the record. A correction run and the run it
                                 corrects are both real payments, and reading the register without knowing which
                                 is which would double the company's apparent wage bill for that month. --}}
                            <span class="block text-[11px] text-[var(--text-muted)]">corrects {{ $row->corrects?->number }}</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap">{{ $row->periodLabel() }}</td>
                    <td class="whitespace-nowrap">{{ $row->pay_date?->format('j M Y') ?? '—' }}</td>
                    <td class="text-right tabular-nums">{{ $row->payslip_count }}</td>
                    <td class="text-right tabular-nums whitespace-nowrap">{{ $row->currency }} {{ number_format((float) $row->net_total, 2) }}</td>
                    <td>
                        @if ($row->status)<x-badge :tone="$row->status->tone()">{{ $row->status->label }}</x-badge>@endif
                        @if ($row->isLocked())
                            <span class="block text-[11px] text-[var(--text-muted)]">locked {{ $row->locked_at?->format('j M Y') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
