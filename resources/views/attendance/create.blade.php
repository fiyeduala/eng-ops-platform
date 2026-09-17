<x-layouts.app title="Record a missed shift">
    <x-page-header title="Record a missed shift" description="For somebody who worked but could not clock in. You cannot record your own."/>
    @include('finance.errors')
    <form method="POST" action="{{ route('attendance.store') }}" class="card max-w-2xl p-5">
        @csrf
        <x-field label="Person" name="employee_id" required>
            <x-select name="employee_id" :options="$employees" :selected="old('employee_id')" placeholder="Choose a person"/>
        </x-field>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <x-field label="Started" name="clock_in_at" required><x-input type="datetime-local" name="clock_in_at" :value="old('clock_in_at')"/></x-field>
            <x-field label="Finished" name="clock_out_at" required><x-input type="datetime-local" name="clock_out_at" :value="old('clock_out_at')"/></x-field>
        </div>
        <x-field label="Why was it missed?" name="reason" required class="mt-4">
            <x-textarea name="reason" rows="3">{{ old('reason') }}</x-textarea>
        </x-field>
        <div class="mt-6 flex gap-2"><button class="btn btn-primary">Record shift</button><a href="{{ route('attendance.index') }}" class="btn btn-ghost">Cancel</a></div>
    </form>
</x-layouts.app>
