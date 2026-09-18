{{--
    A run is a period, a frequency and a currency. Nothing on this form is a figure.

    Every amount on a payroll run is produced by calculating it, never typed — which is why there is
    no gross, net or payslip count here and why the service strips them from input regardless (§84).
    A form that could post a net total would be a form that could pay somebody a number nobody
    worked out.
--}}
<div class="grid gap-4 md:grid-cols-2">
    <x-field label="Name" name="name" required help="What this run is called on the register — “September 2026 payroll” reads better in three years than a number alone.">
        <x-input name="name" :value="old('name', $run->name)" required/>
    </x-field>

    <x-field label="Frequency" name="frequency" required help="How often this payroll is paid. It decides nothing by itself; it is how the register is read.">
        <x-select name="frequency" :options="$frequencies" :selected="old('frequency', $run->frequency)"/>
    </x-field>

    <x-field label="Period starts" name="period_start" required help="The first day the run pays for. Anybody hired after it is paid pro rata.">
        <x-input type="date" name="period_start" :value="old('period_start', $run->period_start?->toDateString() ?? $run->period_start)" required/>
    </x-field>

    <x-field label="Period ends" name="period_end" required help="The last day the run pays for. Anybody who left before it is paid pro rata.">
        <x-input type="date" name="period_end" :value="old('period_end', $run->period_end?->toDateString() ?? $run->period_end)" required/>
    </x-field>

    <x-field label="Pay date" name="pay_date" help="When the money actually moves. It may fall inside the period — companies do pay in advance.">
        <x-input type="date" name="pay_date" :value="old('pay_date', $run->pay_date?->toDateString() ?? $run->pay_date)"/>
    </x-field>

    <x-field label="Currency" name="currency" required
             help="Three letters. Anybody whose salary is held in a different currency is skipped with a reason rather than converted — the platform will not invent an exchange rate and pay somebody a figure they cannot check.">
        <x-input name="currency" :value="old('currency', $run->currency)" maxlength="3" class="uppercase" required/>
    </x-field>
</div>

<div class="mt-6">
    <x-field label="Notes" name="notes" help="Anything the approver should know before deciding — a bonus month, a late starter, a correction already agreed.">
        <x-textarea name="notes" rows="3">{{ old('notes', $run->notes) }}</x-textarea>
    </x-field>
</div>
