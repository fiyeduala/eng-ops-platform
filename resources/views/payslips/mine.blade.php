<x-layouts.app title="My payslips">
    <x-page-header title="My payslips" description="Your own pay, one card per period. Nobody reaches this list but you and the people who run payroll."/>

    @if ($payslips->isEmpty())
        <x-empty-state icon="banknotes" title="No payslips yet"
                       description="Payslips appear here once the payroll run covering them has been approved. A run still being worked on is not shown, because the figures in it can still change."/>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($payslips as $payslip)
                <a href="{{ route('payslips.show', $payslip) }}" class="card block p-5 transition hover:border-brand-300">
                    <p class="text-xs text-[var(--text-muted)]">{{ $payslip->run?->periodLabel() }}</p>
                    <p class="mt-2 text-2xl font-semibold tabular-nums text-[var(--text-strong)]">
                        {{ $payslip->currency }} {{ number_format((float) $payslip->net_amount, 2) }}
                    </p>
                    <p class="mt-1 text-xs text-[var(--text-muted)]">
                        Net of {{ number_format((float) $payslip->gross_amount, 2) }} gross
                    </p>
                    @if ($payslip->wasSkipped())
                        {{-- Shown to the person themselves, plainly. Somebody who was not paid should
                             find out here, with the reason, rather than on pay day. --}}
                        <p class="mt-3 rounded bg-amber-50 p-2 text-[11px] text-amber-800">{{ $payslip->skipped_reason }}</p>
                    @endif
                    <p class="mt-3 text-[11px] text-[var(--text-muted)]">{{ $payslip->number }}</p>
                </a>
            @endforeach
        </div>

        <div class="mt-6">{{ $payslips->links() }}</div>
    @endif
</x-layouts.app>
