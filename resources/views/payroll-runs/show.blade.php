<x-layouts.app :title="$run->number">
    <x-page-header :title="$run->name" :description="$run->number.' · '.$run->periodLabel()">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('payroll-runs.edit', $run) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    @if ($run->isLocked())
        {{--
            Said first, before any figure. A locked run is evidence of money that has moved, and the
            only honest way to change it is another run pointing back at this one (§59).
        --}}
        <div class="card mb-6 border-l-4 border-l-amber-400 p-4">
            <p class="text-sm font-medium text-[var(--text-strong)]">This run is locked.</p>
            <p class="mt-1 text-xs text-[var(--text-muted)]">
                It was approved on {{ $run->locked_at?->format('j M Y') }} and nothing in it can be edited or recalculated.
                A mistake found now is corrected by a new run that points back at this one, so both stay on the record.
            </p>
        </div>
    @endif

    @if ($instance)
        <div class="mb-6"><x-workflow-progress :instance="$instance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-5">
                <h2 class="card-title">Totals</h2>
                <p class="mt-1 text-xs text-[var(--text-muted)]">Held on the run so the register does not sum every line to draw itself. They are rebuilt from the payslips each time it is calculated.</p>
                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="text-xs text-[var(--text-muted)]">Gross</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-[var(--text-strong)]">{{ number_format((float) $run->gross_total, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[var(--text-muted)]">Deductions</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-[var(--text-strong)]">{{ number_format((float) $run->deduction_total, 2) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-[var(--text-muted)]">Net</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-[var(--text-strong)]">{{ number_format((float) $run->net_total, 2) }}</dd>
                    </div>
                    <div>
                        {{-- What the company pays, not what the employee receives. Employer pension and
                             levies never appear on a payslip and are invisible without this figure. --}}
                        <dt class="text-xs text-[var(--text-muted)]">Cost to company</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-[var(--text-strong)]">{{ number_format((float) $run->employer_cost_total, 2) }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-[var(--text-muted)]">All figures in {{ $run->currency }}.</p>
            </div>

            <div class="card p-5">
                <div class="flex items-baseline justify-between">
                    <h2 class="card-title">Payslips</h2>
                    <span class="text-xs text-[var(--text-muted)]">{{ $payslips->total() }} in total</span>
                </div>

                @if ($payslips->isEmpty())
                    <p class="mt-3 text-sm text-[var(--text-muted)]">
                        Nothing calculated yet. Calculating the run writes one payslip per person employed for any part of the period.
                    </p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr><th>Payslip</th><th>Person</th><th class="text-right">Gross</th><th class="text-right">Deductions</th><th class="text-right">Net</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($payslips as $payslip)
                                    <tr class="{{ $payslip->wasSkipped() ? 'bg-amber-50/60' : '' }}">
                                        <td class="whitespace-nowrap">
                                            <a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('payslips.show', $payslip) }}">{{ $payslip->number }}</a>
                                        </td>
                                        <td>
                                            {{ $payslip->employee?->fullName() ?? '—' }}
                                            @if ($payslip->wasSkipped())
                                                {{-- The whole point of a skipped payslip: it is on the register,
                                                     with its reason, where somebody can act on it before pay day. --}}
                                                <span class="block text-[11px] text-amber-800">{{ $payslip->skipped_reason }}</span>
                                            @endif
                                        </td>
                                        <td class="text-right tabular-nums">{{ number_format((float) $payslip->gross_amount, 2) }}</td>
                                        <td class="text-right tabular-nums">{{ number_format((float) $payslip->deduction_amount, 2) }}</td>
                                        <td class="text-right font-medium tabular-nums">{{ number_format((float) $payslip->net_amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $payslips->links() }}</div>
                @endif
            </div>

            @if ($run->notes)
                <div class="card p-5"><h2 class="card-title">Notes</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $run->notes }}</p></div>
            @endif

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this run."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$run->status?->label"/>
                    <x-register.fact label="Period" :value="$run->periodLabel()"/>
                    <x-register.fact label="Pay date" :value="$run->pay_date?->format('j M Y')"/>
                    <x-register.fact label="Frequency" :value="\App\Models\PayrollRun::FREQUENCIES[$run->frequency] ?? $run->frequency"/>
                    <x-register.fact label="Currency" :value="$run->currency"/>
                    <x-register.fact label="Payslips" :value="(string) $run->payslip_count"/>
                    <x-register.fact label="Calculated" :value="$run->calculated_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Locked" :value="$run->locked_at?->format('j M Y H:i')"/>
                    @if ($run->isCorrection())
                        <x-register.fact label="Corrects" :value="$run->corrects?->number"/>
                    @endif
                    @if ($run->cancelled_at)
                        <x-register.fact label="Cancelled" :value="$run->cancelled_at?->format('j M Y')"/>
                        <x-register.fact label="Because" :value="$run->cancellation_reason"/>
                    @endif
                </dl>

                @if ($run->corrections->isNotEmpty())
                    <div class="mt-5 border-t border-[var(--border)] pt-4">
                        <h3 class="text-xs font-semibold text-[var(--text-strong)]">Corrected by</h3>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($run->corrections as $correction)
                                <li>
                                    <a class="hover:text-brand-600" href="{{ route('payroll-runs.show', $correction) }}">{{ $correction->number }}</a>
                                    <span class="text-xs text-[var(--text-muted)]">{{ $correction->status?->label }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            @if ($canCalculate)
                <form method="POST" action="{{ route('payroll-runs.calculate', $run) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Calculate</h2>
                    <p class="text-xs text-[var(--text-muted)]">
                        Works out every payslip in the run from the pay structure, salaries, approved overtime and unpaid leave.
                        Calculating again replaces everything already worked out — nothing is paid, and nothing is final, until this run is approved.
                    </p>
                    <button class="btn btn-primary btn-sm w-full">Calculate the run</button>
                </form>
            @endif

            @if ($canSubmit)
                <form method="POST" action="{{ route('payroll-runs.submit', $run) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Send for approval</h2>
                    {{--
                        THE DECISION IS NOT TAKEN HERE. This hands the run to the workflow, and it is
                        approved on the approvals screen like a drawing or a purchase order. There is
                        no approve button on this page and there is no approve route behind it.
                    --}}
                    <p class="text-xs text-[var(--text-muted)]">Goes to the approver the workflow names. The run locks when it comes back approved, and not before.</p>
                    <button class="btn btn-primary btn-sm w-full">Submit for approval</button>
                </form>
            @endif

            @if ($canCorrect)
                <form method="POST" action="{{ route('payroll-runs.correct', $run) }}" class="card space-y-3 p-5">@csrf
                    <h2 class="card-title">Open a correction</h2>
                    <p class="text-xs text-[var(--text-muted)]">
                        A new run for the same period, pointing back at this one. Both stay on the record.
                        The period and currency are taken from {{ $run->number }} and are not yours to change here.
                    </p>
                    <x-input name="name" :value="$run->name.' (correction)'" required/>
                    <x-textarea name="notes" rows="2" placeholder="What was wrong with {{ $run->number }}?"></x-textarea>
                    <button class="btn btn-secondary btn-sm w-full">Open correction run</button>
                </form>
            @endif

            @if ($canCancel)
                <form method="POST" action="{{ route('payroll-runs.cancel', $run) }}" class="card space-y-2 p-5">@csrf
                    <x-textarea name="reason" rows="2" placeholder="Why is this run not going ahead?"></x-textarea>
                    <button class="btn btn-ghost btn-sm w-full">Cancel run</button>
                </form>
            @endif

            @if ($canDelete)
                <div class="card p-5">
                    <x-delete-form :action="route('payroll-runs.destroy', $run)" label="Delete run" confirm="Delete this run and everything calculated in it?"/>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
