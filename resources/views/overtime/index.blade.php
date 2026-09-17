<x-layouts.app title="Overtime">
    <x-page-header title="Overtime" description="Ask before you work it; record what you actually worked afterwards.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('overtime.create') }}" class="btn btn-primary btn-sm">Request overtime</a>
            @endif
        </x-slot:actions>
    </x-page-header>
    <div class="mb-4 flex gap-2 text-sm">
        <a href="{{ route('overtime.index', ['view' => 'mine']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-secondary' : 'btn-ghost' }}">My overtime</a>
        <a href="{{ route('overtime.index', ['view' => 'all']) }}" class="btn btn-sm {{ $filters['view'] === 'mine' ? 'btn-ghost' : 'btn-secondary' }}">All I can see</a>
    </div>
    <x-register.filters route="overtime.index" :filters="$filters" :statuses="$statuses" placeholder="Number"/>
    <x-register.table :records="$requests" icon="clock" title="No overtime" description="No overtime request within your scope matches these filters.">
        <x-slot:head><tr><th>Request</th><th>Person</th><th>Day</th><th class="text-right">Approved</th><th class="text-right">Worked</th><th>Project</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($requests as $overtime)
                <tr>
                    <td><a class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('overtime.show', $overtime) }}">{{ $overtime->number }}</a></td>
                    <td>{{ $overtime->employee?->fullName() ?? '—' }}</td>
                    <td class="whitespace-nowrap">{{ $overtime->work_date?->format('D j M Y') }}</td>
                    <td class="text-right font-mono">{{ rtrim(rtrim((string) $overtime->hours, '0'), '.') }}</td>
                    <td class="text-right font-mono">{{ $overtime->worked_hours === null ? '—' : (rtrim(rtrim((string) $overtime->worked_hours, '0'), '.') ?: '0') }}</td>
                    <td>{{ $overtime->project?->name ?? '—' }}</td>
                    <td>@if ($overtime->status)<x-badge :tone="$overtime->status->tone()">{{ $overtime->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
