<x-layouts.app :title="$employee->fullName().' — pay'">
    <x-page-header :title="$employee->fullName()" :description="'Pay and bank details · '.$employee->employee_no">
        <x-slot:actions>
            <a href="{{ route('employees.show', $employee) }}" class="btn btn-ghost btn-sm">Personnel record</a>
        </x-slot:actions>
    </x-page-header>

    {{--
        THIS PAGE IS THE SHARPEST CONFIDENTIALITY BOUNDARY IN THE PLATFORM. Reaching it takes
        `payroll.view_confidential`, which a line manager does not get by managing somebody. The
        warning is on the page because the people who hold that permission should be conscious of it.
    --}}
    <div class="card mb-6 border-l-4 border-l-rose-300 p-4">
        <p class="text-xs text-[var(--text-muted)]">
            What somebody earns and where they are paid. Very few people in the company can open this page, and every
            change made here is recorded against your name. A rise is a new row, never an edit — so the history below
            still explains a payslip from two years ago.
        </p>
    </div>

    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- ------------------------------------------------------------ salary history --}}
            <div class="card p-5">
                <h2 class="card-title">Salary</h2>
                @if ($salaries->isEmpty())
                    <p class="mt-3 text-sm text-[var(--text-muted)]">
                        No salary is on record. A payroll run will still produce a payslip for this person — one that pays
                        nothing and says why — rather than dropping them silently.
                    </p>
                @else
                    <table class="table mt-3">
                        <thead><tr><th>From</th><th>To</th><th class="text-right">Basic</th><th>Frequency</th><th>Reason</th></tr></thead>
                        <tbody>
                            @foreach ($salaries as $salary)
                                <tr class="{{ $salary->effective_to === null ? 'font-medium text-[var(--text-strong)]' : '' }}">
                                    <td class="whitespace-nowrap">{{ $salary->effective_from?->format('j M Y') }}</td>
                                    <td class="whitespace-nowrap">{{ $salary->effective_to?->format('j M Y') ?? 'current' }}</td>
                                    <td class="text-right tabular-nums whitespace-nowrap">{{ $salary->currency }} {{ number_format((float) $salary->basic_amount, 2) }}</td>
                                    <td>{{ $salary->frequencyLabel() }}</td>
                                    <td class="text-xs text-[var(--text-muted)]">{{ $salary->reason }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- ------------------------------------------------------------ assignments --}}
            <div class="card p-5">
                <h2 class="card-title">Components given to this person</h2>
                <p class="mt-1 text-xs text-[var(--text-muted)]">
                    Only the ones they have individually. Statutory items that apply to everybody are not listed here —
                    they are in the pay structure and reach this person automatically.
                </p>
                @if ($assignments->isEmpty())
                    <p class="mt-3 text-sm text-[var(--text-muted)]">Nothing individual. This person is paid on the standard structure alone.</p>
                @else
                    <table class="table mt-3">
                        <thead><tr><th>Component</th><th>From</th><th>To</th><th class="text-right">Override</th><th>Reference</th></tr></thead>
                        <tbody>
                            @foreach ($assignments as $assignment)
                                <tr class="{{ $assignment->is_active ? '' : 'opacity-60' }}">
                                    <td>{{ $assignment->component?->name ?? '—' }}</td>
                                    <td class="whitespace-nowrap">{{ $assignment->effective_from?->format('j M Y') }}</td>
                                    <td class="whitespace-nowrap">{{ $assignment->effective_to?->format('j M Y') ?? '—' }}</td>
                                    <td class="text-right tabular-nums whitespace-nowrap">
                                        @if ($assignment->amount !== null)
                                            {{ number_format((float) $assignment->amount, 2) }}
                                        @elseif ($assignment->rate !== null)
                                            {{ rtrim(rtrim((string) $assignment->rate, '0'), '.') }}%
                                        @else
                                            <span class="text-[var(--text-muted)]">standard</span>
                                        @endif
                                    </td>
                                    <td class="text-xs text-[var(--text-muted)]">{{ $assignment->reference }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- ------------------------------------------------------------ bank --}}
            <div class="card p-5">
                <h2 class="card-title">Bank details</h2>
                <p class="mt-1 text-xs text-[var(--text-muted)]">
                    Account numbers are stored encrypted and are never shown back in full — not here, and not to whoever
                    entered them. Changed bank details are the classic payroll fraud, so every change is recorded with a
                    name and a time against it.
                </p>
                @if ($accounts->isEmpty())
                    <p class="mt-3 text-sm text-[var(--text-muted)]">No bank details on record.</p>
                @else
                    <table class="table mt-3">
                        <thead><tr><th>Bank</th><th>Account name</th><th>Number</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($accounts as $account)
                                <tr class="{{ $account->is_active ? '' : 'opacity-60' }}">
                                    <td>{{ $account->bank_name }}</td>
                                    <td>{{ $account->account_name }}</td>
                                    <td class="tabular-nums">{{ $account->maskedNumber() }}</td>
                                    <td>
                                        @if ($account->is_primary)<x-badge tone="success">Primary</x-badge>@endif
                                        @unless ($account->is_active)<span class="text-[11px] text-[var(--text-muted)]">inactive</span>@endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- ------------------------------------------------------------ the forms --}}
        <div class="space-y-6">
            @if ($canEdit)
                <form method="POST" action="{{ route('employee-pay.salary', $employee) }}" class="card space-y-3 p-5">
                    @csrf
                    <h2 class="card-title">Set a salary</h2>
                    <p class="text-xs text-[var(--text-muted)]">
                        The row currently in force is closed the day before this one starts. Runs already approved are untouched.
                    </p>

                    <x-field label="Basic amount" name="basic_amount" required>
                        <x-input type="number" step="0.01" min="0" name="basic_amount" required/>
                    </x-field>

                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Currency" name="currency" required>
                            <x-input name="currency" :value="old('currency', $currency)" maxlength="3" class="uppercase" required/>
                        </x-field>
                        <x-field label="Frequency" name="frequency" required>
                            <x-select name="frequency" :options="$frequencies" :selected="old('frequency', 'monthly')"/>
                        </x-field>
                    </div>

                    <x-field label="Effective from" name="effective_from" required>
                        <x-input type="date" name="effective_from" :value="old('effective_from', now()->startOfMonth()->toDateString())" required/>
                    </x-field>

                    <x-field label="Hours per period" name="hours_per_period"
                             help="The divisor that prices overtime. Leave it blank if your company has not agreed one — the calculator then drops the overtime line rather than inventing a rate.">
                        <x-input type="number" step="0.01" min="0.01" name="hours_per_period" :value="old('hours_per_period')"/>
                    </x-field>

                    <x-field label="Reason" name="reason" help="Annual review, promotion, correction. Recorded in the history; the figure itself never is.">
                        <x-input name="reason" :value="old('reason')"/>
                    </x-field>

                    <button class="btn btn-primary btn-sm w-full">Record salary</button>
                </form>

                <form method="POST" action="{{ route('employee-pay.component', $employee) }}" class="card space-y-3 p-5">
                    @csrf
                    <h2 class="card-title">Give a component</h2>
                    @if ($components->isEmpty())
                        <p class="text-xs text-[var(--text-muted)]">
                            There is nothing to give. Every component in the pay structure currently applies to everybody,
                            so nobody needs to be given one individually.
                        </p>
                    @else
                        <p class="text-xs text-[var(--text-muted)]">A loan repayment, an allowance agreed for this person alone.</p>

                        <x-field label="Component" name="pay_component_id" required>
                            <x-select name="pay_component_id" :options="$components->pluck('name', 'id')->all()" placeholder="Choose a component"/>
                        </x-field>

                        <div class="grid grid-cols-2 gap-3">
                            <x-field label="From" name="effective_from" required>
                                <x-input type="date" name="effective_from" :value="old('effective_from', now()->startOfMonth()->toDateString())" required/>
                            </x-field>
                            <x-field label="Until" name="effective_to" help="Blank for open-ended.">
                                <x-input type="date" name="effective_to" :value="old('effective_to')"/>
                            </x-field>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <x-field label="Amount" name="amount" help="Blank uses the component's own figure.">
                                <x-input type="number" step="0.01" min="0" name="amount" :value="old('amount')"/>
                            </x-field>
                            <x-field label="Rate %" name="rate">
                                <x-input type="number" step="0.0001" min="0" name="rate" :value="old('rate')"/>
                            </x-field>
                        </div>

                        <x-field label="Reference" name="reference" help="The loan agreement number, the court order, the letter.">
                            <x-input name="reference" :value="old('reference')"/>
                        </x-field>

                        <input type="hidden" name="is_active" value="1">
                        <button class="btn btn-secondary btn-sm w-full">Assign component</button>
                    @endif
                </form>

                <form method="POST" action="{{ route('employee-pay.bank-account', $employee) }}" class="card space-y-3 p-5">
                    @csrf
                    <h2 class="card-title">Record bank details</h2>
                    <p class="text-xs text-[var(--text-muted)]">
                        No format is enforced on the account number. A NUBAN, an eight-digit account with a sort code and
                        an IBAN are all valid here, because a company operating in two countries needs all three.
                    </p>

                    <x-field label="Bank" name="bank_name" required>
                        <x-input name="bank_name" :value="old('bank_name')" required/>
                    </x-field>

                    <x-field label="Account name" name="account_name" required
                             help="Checked against the person's own name by whoever approves the run — a mismatch is the most useful signal there is that something is wrong.">
                        <x-input name="account_name" :value="old('account_name', $employee->fullName())" required/>
                    </x-field>

                    <x-field label="Account number" name="account_number" required>
                        <x-input name="account_number" :value="old('account_number')" required autocomplete="off"/>
                    </x-field>

                    <div class="grid grid-cols-2 gap-3">
                        <x-field label="Sort / branch code" name="branch_code">
                            <x-input name="branch_code" :value="old('branch_code')"/>
                        </x-field>
                        <x-field label="Account type" name="account_type">
                            <x-input name="account_type" :value="old('account_type')"/>
                        </x-field>
                    </div>

                    <label class="flex items-start gap-2 text-sm">
                        <input type="checkbox" name="is_primary" value="1" class="mt-0.5" @checked(old('is_primary', true))>
                        <span>
                            <span class="font-medium text-[var(--text-strong)]">Primary account</span>
                            <span class="block text-[var(--text-muted)]">Where their pay goes. Any other primary account is demoted.</span>
                        </span>
                    </label>
                    <input type="hidden" name="is_active" value="1">

                    <button class="btn btn-secondary btn-sm w-full">Record details</button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.app>
