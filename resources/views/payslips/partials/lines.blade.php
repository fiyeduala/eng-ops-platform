{{--
    The arithmetic, in the order it was done.

    EVERY LINE SHOWS ITS WORKING. A payslip that shows a deduction and no basis is a payslip nobody
    can check, and the first question anybody asks about a deduction is what it was taken from. The
    figures come from the columns copied onto the line at calculation time, never from the live pay
    component — so a payslip from three years ago still explains itself after the pay structure has
    been rewritten, and still explains itself after the component has been deleted.

    EMPLOYER COSTS ARE SHOWN SEPARATELY AND ARE NOT IN THE NET. They are what the company pays on
    somebody's behalf, not money the person receives, and adding them to either column would make the
    payslip wrong in the direction people notice.
--}}
@php
    $earnings = $lines->filter(fn ($line) => $line->isEarning());
    $reliefs = $lines->filter(fn ($line) => $line->isRelief());
    $deductions = $lines->filter(fn ($line) => $line->isDeduction());
    $employerCosts = $lines->filter(fn ($line) => $line->isEmployerCost());
@endphp

<div class="card p-5">
    <h2 class="card-title">Payslip</h2>

    @if ($lines->isEmpty())
        <p class="mt-3 text-sm text-[var(--text-muted)]">There are no lines on this payslip.</p>
    @else
        @foreach ([['Earnings', $earnings], ['Reliefs', $reliefs], ['Deductions', $deductions]] as [$heading, $group])
            @if ($group->isNotEmpty())
                <h3 class="mt-5 text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">{{ $heading }}</h3>
                <table class="table mt-2">
                    <tbody>
                        @foreach ($group as $line)
                            <tr>
                                <td>
                                    {{ $line->name }}
                                    <span class="block text-[11px] text-[var(--text-muted)]">{{ $line->workingLabel() }}</span>
                                </td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ number_format((float) $line->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach

        <dl class="mt-6 space-y-2 border-t border-[var(--border)] pt-4 text-sm">
            <div class="flex justify-between"><dt class="text-[var(--text-muted)]">Gross</dt><dd class="tabular-nums">{{ number_format((float) $payslip->gross_amount, 2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-[var(--text-muted)]">Taxable</dt><dd class="tabular-nums">{{ number_format((float) $payslip->taxable_amount, 2) }}</dd></div>
            <div class="flex justify-between"><dt class="text-[var(--text-muted)]">Deductions</dt><dd class="tabular-nums">{{ number_format((float) $payslip->deduction_amount, 2) }}</dd></div>
            <div class="flex justify-between border-t border-[var(--border)] pt-2 text-base font-semibold text-[var(--text-strong)]">
                <dt>Net pay</dt><dd class="tabular-nums">{{ $payslip->currency }} {{ number_format((float) $payslip->net_amount, 2) }}</dd>
            </div>
        </dl>

        @if ($employerCosts->isNotEmpty())
            <div class="mt-6 border-t border-[var(--border)] pt-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-[var(--text-muted)]">Paid by the company</h3>
                <p class="mt-1 text-[11px] text-[var(--text-muted)]">Not deducted from your pay and not part of the net above.</p>
                <table class="table mt-2">
                    <tbody>
                        @foreach ($employerCosts as $line)
                            <tr>
                                <td>{{ $line->name }}<span class="block text-[11px] text-[var(--text-muted)]">{{ $line->workingLabel() }}</span></td>
                                <td class="text-right tabular-nums whitespace-nowrap">{{ number_format((float) $line->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
