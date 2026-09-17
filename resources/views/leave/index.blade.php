<x-layouts.app title="Leave">
    <x-page-header title="Leave" description="Book time off. Your line manager decides it in My Approvals.">
        <x-slot:actions>
            <a href="{{ route('leave.calendar') }}" class="btn btn-secondary btn-sm">Team calendar</a>
            @if ($canCreate)
                <a href="{{ route('leave.create') }}" class="btn btn-primary btn-sm">Request leave</a>
            @endif
        </x-slot:actions>
    </x-page-header>
    @include('leave.partials.balances')
    <div class="mb-4 flex gap-2 text-sm">
        <a href="{{ route('leave.index', ['view' => 'mine']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-secondary' : 'btn-ghost' }}">My leave</a>
        <a href="{{ route('leave.index', ['view' => 'all']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-ghost' : 'btn-secondary' }}">All I can see</a>
    </div>
    <x-register.filters route="leave.index" :filters="$filters" :statuses="$statuses" placeholder="Number"/>
    <x-register.table :records="$requests" icon="calendar" title="No leave" description="No leave request within your scope matches these filters.">
        <x-slot:head><tr><th>Request</th><th>Person</th><th>Type</th><th>Dates</th><th class="text-right">Days</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($requests as $leave)
                <tr>
                    <td><a class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('leave.show', $leave) }}">{{ $leave->number }}</a></td>
                    <td>{{ $leave->employee?->fullName() ?? '—' }}</td>
                    <td>{{ $leave->leaveType?->name }}</td>
                    <td class="whitespace-nowrap">{{ $leave->periodLabel() }}</td>
                    <td class="text-right font-mono">{{ rtrim(rtrim((string) $leave->days, '0'), '.') }}</td>
                    <td>@if ($leave->status)<x-badge :tone="$leave->status->tone()">{{ $leave->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
