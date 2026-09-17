<x-layouts.app title="Team leave calendar">
    <x-page-header title="Team leave calendar" :description="$month->format('F Y').' — approved and pending leave for the people you can see.'">
        <x-slot:actions>
            <a href="{{ route('leave.calendar', ['month' => $month->subMonth()->format('Y-m')]) }}" class="btn btn-ghost btn-sm">← {{ $month->subMonth()->format('M') }}</a>
            <a href="{{ route('leave.calendar', ['month' => $month->addMonth()->format('Y-m')]) }}" class="btn btn-ghost btn-sm">{{ $month->addMonth()->format('M') }} →</a>
            <a href="{{ route('leave.index') }}" class="btn btn-secondary btn-sm">Leave</a>
        </x-slot:actions>
    </x-page-header>
    {{-- Types are shown only on your own row, or to HR. Everybody else sees "Away" (§68). --}}
    <div class="card overflow-x-auto">
        <table class="table text-xs">
            <thead>
                <tr>
                    <th class="sticky left-0 bg-[var(--surface-card)]">Person</th>
                    @foreach ($days as $day)
                        <th class="px-1 text-center {{ $day['date']->isWeekend() || $day['holiday'] ? 'bg-[var(--surface-muted)]' : '' }}" title="{{ $day['holiday'] }}">{{ $day['date']->format('j') }}<span class="block font-normal">{{ substr($day['date']->format('D'), 0, 1) }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $requests)
                    <tr>
                        <td class="sticky left-0 whitespace-nowrap bg-[var(--surface-card)]">{{ $requests->first()->employee?->fullName() ?? '—' }}</td>
                        @foreach ($days as $day)
                            @php
                                $ymd = $day['date']->toDateString();
                                $hit = $requests->first(fn ($r) => $ymd >= $r->start_date->toDateString() && $ymd <= $r->end_date->toDateString());
                            @endphp
                            <td class="px-1 text-center {{ $day['date']->isWeekend() || $day['holiday'] ? 'bg-[var(--surface-muted)]' : '' }}">
                                @if ($hit)
                                    @php($label = ($showTypes || (int) $hit->requester_id === (int) auth()->id()) ? $hit->leaveType?->name : 'Away')
                                    <span title="{{ $label }}{{ $hit->statusKey() === 'submitted' ? ' (awaiting approval)' : '' }}"
                                          class="inline-block h-4 w-4 rounded {{ $hit->statusKey() === 'approved' ? 'bg-brand-500' : 'border border-brand-500' }}"></span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($days) + 1 }}" class="py-10 text-center">Nobody you can see is away this month.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-[var(--text-muted)]">Filled: approved. Outlined: awaiting approval. Shaded days are weekends or public holidays.</p>
</x-layouts.app>
