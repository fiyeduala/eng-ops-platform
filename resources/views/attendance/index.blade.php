<x-layouts.app title="Attendance">
    <x-page-header title="Attendance" description="Clock in when you start and out when you finish. The time is taken from the server, not your phone.">
        <x-slot:actions>
            @if ($canRecordFor)
                <a href="{{ route('attendance.create') }}" class="btn btn-secondary btn-sm">Record a missed shift</a>
            @endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            @if ($canClock)
                {{-- The clock card: one big button, usable one-handed on a 375px phone. --}}
                <div class="card p-5"
                     x-data="{
                        capture: @js($capturesLocation),
                        busy: false,
                        send(form) {
                            if (! this.capture || ! navigator.geolocation) { form.submit(); return; }
                            this.busy = true;
                            navigator.geolocation.getCurrentPosition(
                                (p) => { form.latitude.value = p.coords.latitude.toFixed(7); form.longitude.value = p.coords.longitude.toFixed(7); form.submit(); },
                                () => form.submit(),
                                { timeout: 8000, maximumAge: 60000 }
                            );
                        }
                     }">
                    @if ($open)
                        <p class="text-xs text-[var(--text-muted)]">On shift since</p>
                        <p class="mt-1 font-mono text-3xl font-semibold text-[var(--text-strong)]">{{ $open->clock_in_at->format('H:i') }}</p>
                        <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $open->clock_in_at->format('D j M') }}@if ($open->project) · {{ $open->project->name }}@endif</p>
                        <form method="POST" action="{{ route('attendance.clock-out') }}" class="mt-5" x-on:submit.prevent="send($el)">
                            @csrf
                            <input type="hidden" name="latitude"><input type="hidden" name="longitude">
                            <button class="btn btn-danger w-full py-4 text-lg" x-bind:disabled="busy">
                                <span x-show="! busy">Clock out</span><span x-show="busy" x-cloak>Getting your location…</span>
                            </button>
                        </form>
                    @else
                        <p class="text-xs text-[var(--text-muted)]">Not clocked in</p>
                        <p class="mt-1 font-mono text-3xl font-semibold text-[var(--text-strong)]">{{ now()->format('H:i') }}</p>
                        <form method="POST" action="{{ route('attendance.clock-in') }}" class="mt-5 space-y-3" x-on:submit.prevent="send($el)">
                            @csrf
                            <input type="hidden" name="latitude"><input type="hidden" name="longitude">
                            @if ($projects !== [])
                                <x-field label="Project" name="project_id"><x-select name="project_id" :options="$projects" :selected="old('project_id')" placeholder="None"/></x-field>
                            @endif
                            @if ($locations !== [])
                                <x-field label="Site" name="location_id"><x-select name="location_id" :options="$locations" :selected="old('location_id')" placeholder="None"/></x-field>
                            @endif
                            <button class="btn btn-primary w-full py-4 text-lg" x-bind:disabled="busy">
                                <span x-show="! busy">Clock in</span><span x-show="busy" x-cloak>Getting your location…</span>
                            </button>
                        </form>
                    @endif
                    @if ($capturesLocation)
                        <p class="mt-3 text-xs text-[var(--text-muted)]">Your company records where you clock in from. If you refuse the location request you are still clocked in.</p>
                    @endif
                </div>
            @endif

            @if ($mine->isNotEmpty())
                <div class="card p-5">
                    <h2 class="card-title">My recent shifts</h2>
                    <ul class="mt-3 divide-y divide-[var(--border)] text-sm">
                        @foreach ($mine as $record)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <div>
                                    <p class="font-medium">{{ $record->work_date->format('D j M') }}</p>
                                    <p class="text-xs text-[var(--text-muted)]">{{ $record->clock_in_at->format('H:i') }} – {{ $record->clock_out_at?->format('H:i') ?? 'open' }}@if ($record->adjusted_at) · corrected by {{ $record->adjuster?->name ?? 'a manager' }}@endif</p>
                                </div>
                                <span class="font-mono">{{ $record->isOpen() ? '—' : $record->hoursLabel() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="lg:col-span-2">
            <div class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="card-title">Team on {{ $date->format('D j M Y') }}</h2>
                    <form method="GET" action="{{ route('attendance.index') }}" class="flex items-center gap-2">
                        <x-input type="date" name="date" :value="$date->toDateString()"/>
                        <button class="btn btn-secondary btn-sm">Show</button>
                    </form>
                </div>
                @if ($team->isEmpty())
                    <p class="mt-4 text-sm text-[var(--text-muted)]">Nobody you can see clocked in on this day.</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="table w-full text-sm">
                            <thead><tr><th>Person</th><th>In</th><th>Out</th><th class="text-right">Hours</th><th>Project</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($team as $record)
                                    <tr>
                                        <td>{{ $record->employee?->fullName() ?? '—' }}
                                            @if ($record->source === \App\Models\AttendanceRecord::SOURCE_MANUAL)<x-badge tone="neutral">entered</x-badge>@endif
                                            @if ($record->adjusted_at)<x-badge tone="warning">corrected</x-badge>@endif
                                            @if ($service->isLong($record))<x-badge tone="danger">long</x-badge>@endif
                                        </td>
                                        <td class="font-mono">{{ $record->clock_in_at->format('H:i') }}</td>
                                        <td class="font-mono">{{ $record->clock_out_at?->format('H:i') ?? '—' }}</td>
                                        <td class="text-right font-mono">{{ $record->isOpen() ? 'on shift' : $record->hoursLabel() }}</td>
                                        <td>{{ $record->project?->name ?? '—' }}</td>
                                        <td class="text-right">
                                            @can('update', $record)
                                                <a href="{{ route('attendance.edit', $record) }}" class="btn btn-ghost btn-sm">Correct</a>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.app>
