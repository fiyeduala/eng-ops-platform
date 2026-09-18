<x-layouts.app title="Exits">
    <x-page-header title="Exits" description="People leaving, what they still hold, and the day their access stops.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('employee-exits.create') }}" class="btn btn-primary btn-sm">Open an exit</a>
            @endif
        </x-slot:actions>
    </x-page-header>
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'In progress', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('employee-exits.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>
    <x-register.filters route="employee-exits.index" :filters="$filters" :statuses="$statuses" placeholder="Number"/>
    <x-register.table :records="$exits" icon="user" title="No exits" description="No exit within your scope matches these filters.">
        <x-slot:head><tr><th>Number</th><th>Person</th><th>Reason</th><th>Last day</th><th>Cleared</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($exits as $exit)
                <tr>
                    <td><a class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('employee-exits.show', $exit) }}">{{ $exit->number }}</a></td>
                    <td>{{ $exit->employee?->fullName() ?? '—' }}</td>
                    <td>{{ $exit->reason }}</td>
                    <td class="whitespace-nowrap">{{ $exit->last_working_day?->format('j M Y') }}</td>
                    <td class="whitespace-nowrap">{{ $exit->cleared_at?->format('j M Y') ?? '—' }}</td>
                    <td>@if ($exit->status)<x-badge :tone="$exit->status->tone()">{{ $exit->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
