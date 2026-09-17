<x-layouts.app title="Correct a shift">
    <x-page-header title="Correct a shift" :description="$record->employee?->fullName().' — '.$record->work_date->format('D j M Y')"/>
    @include('finance.errors')
    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('attendance.update', $record) }}" class="card p-5 lg:col-span-2">
            @csrf @method('PUT')
            <div class="grid gap-4 md:grid-cols-2">
                <x-field label="Clocked in" name="clock_in_at" required>
                    <x-input type="datetime-local" name="clock_in_at" :value="old('clock_in_at', $record->clock_in_at->format('Y-m-d\TH:i'))"/>
                </x-field>
                <x-field label="Clocked out" name="clock_out_at" help="Leave blank if the shift is still open.">
                    <x-input type="datetime-local" name="clock_out_at" :value="old('clock_out_at', $record->clock_out_at?->format('Y-m-d\TH:i'))"/>
                </x-field>
            </div>
            <x-field label="Why is this being corrected?" name="reason" required class="mt-4" help="Kept in the shift's history with the times before and after.">
                <x-textarea name="reason" rows="3">{{ old('reason') }}</x-textarea>
            </x-field>
            <div class="mt-6 flex gap-2"><button class="btn btn-primary">Save correction</button><a href="{{ route('attendance.index', ['date' => $record->work_date->toDateString()]) }}" class="btn btn-ghost">Cancel</a></div>
        </form>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Recorded as" :value="$record->source === \App\Models\AttendanceRecord::SOURCE_MANUAL ? 'Entered by a manager' : 'Clocked by the person'"/>
                    <x-register.fact label="Hours" :value="$record->isOpen() ? 'On shift' : $record->hoursLabel()"/>
                    <x-register.fact label="Last corrected" :value="$record->adjusted_at ? $record->adjusted_at->format('j M Y H:i').' by '.($record->adjuster?->name ?? 'unknown') : null"/>
                    @if ($record->hasLocation())
                        <x-register.fact label="Clock-in position" :value="$record->clock_in_latitude.', '.$record->clock_in_longitude"/>
                    @endif
                </dl>
            </div>
            <x-timeline :entries="$timeline" title="History" empty="This shift has not been corrected before."/>
        </div>
    </div>
</x-layouts.app>
