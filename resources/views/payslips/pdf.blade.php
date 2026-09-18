{{--
    The payslip as a document — the thing a person keeps, shows a landlord, or takes to a bank.

    Rendered by Dompdf, so the markup is tables and inline styles rather than anything modern.
    PdfRenderer supplies the base stylesheet (h1/h2, table borders, .muted, .danger, .ok).

    THIS PAGE SHOWS ONE PERSON AND NOBODY ELSE. There is no run total on it, no colleague, and no
    company wage bill — a payslip that carried the run's figures would hand everybody who receives
    one a number they were never entitled to (§68).

    EVERY FIGURE CARRIES ITS WORKING, from the columns frozen onto the line when the run was
    calculated. A payslip has to still explain itself after the pay structure behind it has been
    rewritten, which is exactly why nothing here reads the live pay component.

    EVERYTHING IS ESCAPED — Blade does that by default, and there is no {!! !!} on this page. The
    component names are typed by users.

    Expects: $payslip (with run, employee.position, lines), $lines
--}}
@php
    $employee = $payslip->employee;
    $run = $payslip->run;
    $org = $payslip->organization;
    $earnings = $lines->filter(fn ($line) => $line->isEarning());
    $reliefs = $lines->filter(fn ($line) => $line->isRelief());
    $deductions = $lines->filter(fn ($line) => $line->isDeduction());
    $employerCosts = $lines->filter(fn ($line) => $line->isEmployerCost());
@endphp

<h1 style="margin-bottom: 1mm;">Payslip</h1>
<p class="muted" style="margin-bottom: 5mm;">{{ $org?->name }}</p>

{{-- ---------------------------------------------------------------- title block --}}
<table style="margin-bottom: 5mm;">
    <tr>
        <th style="width: 22%;">Payslip no.</th>
        <td style="width: 28%;">{{ $payslip->number }}</td>
        <th style="width: 22%;">Pay period</th>
        <td style="width: 28%;">{{ $run?->periodLabel() }}</td>
    </tr>
    <tr>
        <th>Name</th>
        <td>{{ $employee?->fullName() }}</td>
        <th>Pay date</th>
        <td>{{ $run?->pay_date?->format('j F Y') ?? '—' }}</td>
    </tr>
    <tr>
        <th>Staff number</th>
        <td>{{ $employee?->employee_no }}</td>
        <th>Position</th>
        <td>{{ $employee?->position?->title ?? '—' }}</td>
    </tr>
</table>

@if ($payslip->wasSkipped())
    <p class="danger" style="margin-bottom: 5mm;">Nothing was paid on this payslip. {{ $payslip->skipped_reason }}</p>
@endif

{{-- ---------------------------------------------------------------- the arithmetic --}}
@foreach ([['Earnings', $earnings], ['Reliefs', $reliefs], ['Deductions', $deductions]] as [$heading, $group])
    @if ($group->isNotEmpty())
        <h2>{{ $heading }}</h2>
        <table style="margin-bottom: 4mm;">
            <tr>
                <th style="width: 45%;">Item</th>
                <th style="width: 35%;">How it was worked out</th>
                <th style="width: 20%; text-align: right;">Amount</th>
            </tr>
            @foreach ($group as $line)
                <tr>
                    <td>{{ $line->name }}</td>
                    <td class="muted">{{ $line->workingLabel() }}</td>
                    <td style="text-align: right;">{{ number_format((float) $line->amount, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endforeach

{{-- ---------------------------------------------------------------- the summary --}}
<table style="margin-bottom: 5mm;">
    <tr><th style="width: 80%;">Gross pay</th><td style="text-align: right;">{{ number_format((float) $payslip->gross_amount, 2) }}</td></tr>
    <tr><th>Taxable pay</th><td style="text-align: right;">{{ number_format((float) $payslip->taxable_amount, 2) }}</td></tr>
    <tr><th>Total deductions</th><td style="text-align: right;">{{ number_format((float) $payslip->deduction_amount, 2) }}</td></tr>
    <tr>
        <th>Net pay ({{ $payslip->currency }})</th>
        <td style="text-align: right;"><strong>{{ number_format((float) $payslip->net_amount, 2) }}</strong></td>
    </tr>
</table>

@if ($employerCosts->isNotEmpty())
    <h2>Paid by the company on your behalf</h2>
    <p class="muted" style="margin-bottom: 2mm;">Not deducted from your pay and not included in the net figure above.</p>
    <table style="margin-bottom: 5mm;">
        @foreach ($employerCosts as $line)
            <tr>
                <td style="width: 80%;">{{ $line->name }} <span class="muted">— {{ $line->workingLabel() }}</span></td>
                <td style="text-align: right;">{{ number_format((float) $line->amount, 2) }}</td>
            </tr>
        @endforeach
    </table>
@endif

{{-- ---------------------------------------------------------------- the adjustments --}}
@if ((float) $payslip->period_fraction < 1 || (float) $payslip->overtime_hours > 0 || (float) $payslip->unpaid_leave_days > 0)
    <h2>Adjustments this period</h2>
    <table style="margin-bottom: 5mm;">
        @if ((float) $payslip->period_fraction < 1)
            <tr>
                <th style="width: 40%;">Proportion of period employed</th>
                <td>{{ rtrim(rtrim(number_format((float) $payslip->period_fraction * 100, 2), '0'), '.') }}%</td>
            </tr>
        @endif
        @if ((float) $payslip->overtime_hours > 0)
            <tr><th>Approved overtime</th><td>{{ number_format((float) $payslip->overtime_hours, 2) }} hours</td></tr>
        @endif
        @if ((float) $payslip->unpaid_leave_days > 0)
            <tr><th>Unpaid leave</th><td>{{ number_format((float) $payslip->unpaid_leave_days, 2) }} days</td></tr>
        @endif
    </table>
@endif

<p class="muted">
    Payroll run {{ $run?->number }}, approved {{ $run?->locked_at?->format('j F Y') ?? '—' }}.
    If anything here looks wrong, raise it with payroll before the next run rather than after it.
</p>
