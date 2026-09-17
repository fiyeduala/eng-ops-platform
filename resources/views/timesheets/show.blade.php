<x-layouts.app :title="$sheet->number">
    <x-page-header :title="$sheet->employee?->fullName().' — '.$sheet->periodLabel()" :description="$sheet->number">
        <x-slot:actions>
            @if ($myApproval)<a href="{{ route('approvals.show', $myApproval) }}" class="btn btn-primary btn-sm">Decide in My Approvals</a>@endif
            @if ($canEdit)<a href="{{ route('timesheets.edit', $sheet) }}" class="btn btn-secondary btn-sm">Fill in hours</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php
        $status = $sheet->statusKey();
        $decisionTitles = ['rejected' => 'Reason for rejection', 'draft' => 'Returned for changes', 'approved' => 'Approver comment'];
        $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0';
        $dayTotals = [];
        foreach ($days as $day) {
            $key = $day->toDateString();
            $dayTotals[$key] = array_sum(array_map(fn ($line) => (float) ($line['hours'][$key] ?? 0), $lines));
        }
    @endphp

    @if ($instance)
        <div class="mb-6"><x-workflow-progress :instance="$instance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table text-sm">
                        <thead>
                            <tr>
                                <th>Project / task</th>
                                @foreach ($days as $day)<th class="text-right">{{ $day->format('D') }}<br><span class="font-normal text-[var(--text-muted)]">{{ $day->format('j M') }}</span></th>@endforeach
                                <th class="text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lines as $line)
                                <tr>
                                    <td>
                                        <p class="font-medium">{{ $line['project']?->name ?? 'No project' }}</p>
                                        @if ($line['task'])<p class="text-xs text-[var(--text-muted)]">{{ $line['task']->number }} {{ $line['task']->title }}</p>@endif
                                        @if ($line['description'])<p class="text-xs text-[var(--text-muted)]">{{ $line['description'] }}</p>@endif
                                    </td>
                                    @foreach ($days as $day)<td class="text-right font-mono">{{ $line['hours'][$day->toDateString()] ?? '' }}</td>@endforeach
                                    <td class="text-right font-mono font-semibold">{{ $fmt(array_sum(array_map('floatval', $line['hours']))) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($days) + 2 }}" class="text-[var(--text-muted)]">No hours booked yet.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="font-semibold">
                                <td>Booked</td>
                                @foreach ($days as $day)<td class="text-right font-mono">{{ $fmt($dayTotals[$day->toDateString()]) }}</td>@endforeach
                                <td class="text-right font-mono">{{ $fmt($sheet->total_hours) }}</td>
                            </tr>
                            <tr class="text-[var(--text-muted)]">
                                <td>Clocked</td>
                                @foreach ($days as $day)<td class="text-right font-mono">{{ isset($clocked[$day->toDateString()]) ? $fmt($clocked[$day->toDateString()] / 60) : '' }}</td>@endforeach
                                <td class="text-right font-mono">{{ $clocked === [] ? '' : $fmt(array_sum($clocked) / 60) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            @if ($sheet->notes)
                <div class="card p-5"><h2 class="card-title">Notes</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $sheet->notes }}</p></div>
            @endif
            @if ($sheet->decision_reason && isset($decisionTitles[$status]))
                <div class="card p-5"><h2 class="card-title">{{ $decisionTitles[$status] }}</h2><p class="mt-2 whitespace-pre-line text-sm">{{ $sheet->decision_reason }}</p></div>
            @endif
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this timesheet."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$sheet->status?->label"/>
                    <x-register.fact label="Person" :value="$sheet->employee?->fullName()"/>
                    <x-register.fact label="Unit" :value="$sheet->orgUnit?->name"/>
                    <x-register.fact label="Total hours" :value="$fmt($sheet->total_hours)"/>
                    <x-register.fact label="Submitted" :value="$sheet->submitted_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Decided" :value="$sheet->decided_at?->format('j M Y H:i')"/>
                    @if ($sheet->creator && (int) $sheet->created_by !== (int) $sheet->requester_id)
                        <x-register.fact label="Opened by" :value="$sheet->creator->name"/>
                    @endif
                </dl>
                @if ($canSubmit)
                    <form method="POST" action="{{ route('timesheets.submit', $sheet) }}" class="mt-5">@csrf<button class="btn btn-primary btn-sm w-full">Submit for approval</button></form>
                @endif
            </div>
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('timesheets.destroy', $sheet)" label="Delete draft" confirm="Delete this draft timesheet?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
