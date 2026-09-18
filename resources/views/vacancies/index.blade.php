<x-layouts.app title="Vacancies">
    <x-page-header title="Vacancies" description="Heads asked for, heads approved, and how many are still to fill.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('vacancies.create') }}" class="btn btn-primary btn-sm">New vacancy</a>
            @endif
        </x-slot:actions>
    </x-page-header>
    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'Open', 'mine' => 'Mine', 'closed' => 'Closed', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('vacancies.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>
    <x-register.filters route="vacancies.index" :filters="$filters" :statuses="$statuses" placeholder="Number or title"/>
    <x-register.table :records="$vacancies" icon="users" title="No vacancies" description="No vacancy within your scope matches these filters.">
        <x-slot:head><tr><th>Number</th><th>Job</th><th>Department</th><th class="text-right">Wanted</th><th class="text-right">Hired</th><th>Closes</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($vacancies as $vacancy)
                <tr>
                    <td><a class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('vacancies.show', $vacancy) }}">{{ $vacancy->number }}</a></td>
                    <td>{{ $vacancy->title }}</td>
                    <td>{{ $vacancy->orgUnit?->name ?? '—' }}</td>
                    <td class="text-right font-mono">{{ $vacancy->openings }}</td>
                    <td class="text-right font-mono">{{ $vacancy->hired_count }}</td>
                    <td class="whitespace-nowrap">{{ $vacancy->closes_on?->format('j M Y') ?? '—' }}</td>
                    <td>@if ($vacancy->status)<x-badge :tone="$vacancy->status->tone()">{{ $vacancy->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
