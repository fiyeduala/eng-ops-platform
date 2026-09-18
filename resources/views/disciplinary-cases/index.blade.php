<x-layouts.app title="Conduct">
    <x-page-header title="Conduct" description="Disciplinary cases you are entitled to see. For most people that is none, and that is the design working.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('disciplinary-cases.create') }}" class="btn btn-primary btn-sm">Open a case</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'Open', 'live' => 'Live sanctions', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('disciplinary-cases.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <input type="hidden" name="view" value="{{ $filters['view'] }}">
        <div class="min-w-48">
            <label class="label" for="severity">Severity</label>
            <x-select name="severity" :options="$severities" :selected="$filters['severity'] ?: null" placeholder="Any severity"/>
        </div>
        <button class="btn btn-secondary btn-sm">Apply</button>
        @if ($filters['severity'])<a href="{{ route('disciplinary-cases.index', ['view' => $filters['view']]) }}" class="btn btn-ghost btn-sm">Clear</a>@endif
    </form>

    <x-register.filters route="disciplinary-cases.index" :filters="$filters" :statuses="$statuses" placeholder="Case number"/>

    <x-register.table :records="$cases" icon="shield" title="No cases"
                      description="Nothing here for you to see. These records are confidential by default, so an empty register is not evidence that the company has none.">
        <x-slot:head><tr><th>Number</th><th>Person</th><th>Category</th><th>Severity</th><th>Reported</th><th>Outcome</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($cases as $case)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('disciplinary-cases.show', $case) }}">{{ $case->number }}</a></td>
                    <td>{{ $case->employee?->fullName() ?? '—' }}</td>
                    <td class="capitalize">{{ str_replace('_', ' ', (string) $case->category) }}</td>
                    <td>
                        <x-badge :tone="$case->severity === 'gross' ? 'danger' : ($case->severity === 'serious' ? 'warning' : 'muted')">
                            {{ $severities[$case->severity] ?? ucfirst((string) $case->severity) }}
                        </x-badge>
                    </td>
                    <td class="whitespace-nowrap">{{ $case->reported_on?->format('j M Y') }}</td>
                    <td>
                        {{ $case->outcomeLabel() }}
                        @if ($case->isDecided() && ! $case->sanctionIsLive())
                            <span class="block text-[11px] text-[var(--text-muted)]">no longer live</span>
                        @endif
                    </td>
                    <td>@if ($case->status)<x-badge :tone="$case->status->tone()">{{ $case->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
