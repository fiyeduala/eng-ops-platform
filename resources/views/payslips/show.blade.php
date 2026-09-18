<x-layouts.app :title="$payslip->number">
    <x-page-header :title="'Payslip '.$payslip->number"
                   :description="($payslip->employee?->fullName() ?? '').' · '.($payslip->run?->periodLabel() ?? '')">
        <x-slot:actions>
            @can('download', $payslip)
                <a href="{{ route('payslips.download', $payslip) }}" class="btn btn-secondary btn-sm">Download PDF</a>
            @endcan
        </x-slot:actions>
    </x-page-header>

    @if ($payslip->wasSkipped())
        <div class="card mb-6 border-l-4 border-l-amber-400 p-4">
            <p class="text-sm font-medium text-[var(--text-strong)]">Nothing was paid on this payslip.</p>
            <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $payslip->skipped_reason }}</p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @include('payslips.partials.lines', ['payslip' => $payslip, 'lines' => $lines])
            <x-timeline :entries="$timeline" title="History" empty="Nothing has happened to this payslip since it was calculated."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Person" :value="$payslip->employee?->fullName()"/>
                    <x-register.fact label="Staff number" :value="$payslip->employee?->employee_no"/>
                    <x-register.fact label="Position" :value="$payslip->employee?->position?->title"/>
                    <x-register.fact label="Period" :value="$payslip->run?->periodLabel()"/>
                    <x-register.fact label="Pay date" :value="$payslip->run?->pay_date?->format('j M Y')"/>
                    <x-register.fact label="Currency" :value="$payslip->currency"/>
                    @if ((float) $payslip->period_fraction < 1)
                        {{-- Only shown when it is not 1, because for almost everybody it is, and a row
                             reading "100%" on every payslip trains people to stop reading the panel. --}}
                        <x-register.fact label="Period worked"
                                         :value="rtrim(rtrim(number_format((float) $payslip->period_fraction * 100, 2), '0'), '.').'% of the period'"/>
                    @endif
                    @if ((float) $payslip->overtime_hours > 0)
                        <x-register.fact label="Overtime hours" :value="number_format((float) $payslip->overtime_hours, 2)"/>
                    @endif
                    @if ((float) $payslip->unpaid_leave_days > 0)
                        <x-register.fact label="Unpaid leave" :value="number_format((float) $payslip->unpaid_leave_days, 2).' days'"/>
                    @endif
                    <x-register.fact label="Run" :value="$payslip->run?->number"/>
                </dl>
            </div>

            @if ($payslip->run && ! $payslip->isLocked())
                <div class="card p-5">
                    <p class="text-xs text-[var(--text-muted)]">
                        The run this belongs to has not been approved yet, so these figures can still change.
                    </p>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
