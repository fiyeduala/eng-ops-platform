<x-layouts.app title="Visitors">
    <x-page-header title="Visitors" description="Who is expected, who is here, and who has been. The gate log.">
        <x-slot:actions>
            @if ($canRollCall)
                <a href="{{ route('visitors.roll-call') }}" class="btn btn-secondary btn-sm">Who is on site</a>
            @endif
            @if ($canCreate)
                <a href="{{ route('visitors.create') }}" class="btn btn-primary btn-sm">Book a visitor</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'Expected and on site', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('visitors.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <input type="hidden" name="view" value="{{ $filters['view'] }}">
        <div class="min-w-48">
            <label class="label" for="purpose">Reason</label>
            <x-select name="purpose" :options="$purposes" :selected="$filters['purpose'] ?: null" placeholder="Any reason"/>
        </div>
        <button class="btn btn-secondary btn-sm">Apply</button>
        @if ($filters['purpose'])<a href="{{ route('visitors.index', ['view' => $filters['view']]) }}" class="btn btn-ghost btn-sm">Clear</a>@endif
    </form>

    <x-register.filters route="visitors.index" :filters="$filters" :statuses="$statuses" placeholder="Name, company, badge or vehicle"/>

    <x-register.table :records="$visitors" icon="users" title="No visitors"
                      description="Nothing booked and nobody signed in that you can see.">
        <x-slot:head><tr><th>Number</th><th>Visitor</th><th>Here to see</th><th>Site</th><th>When</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($visitors as $visit)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('visitors.show', $visit) }}">{{ $visit->number }}</a></td>
                    <td>
                        {{ $visit->full_name }}
                        @if ($visit->company_name)<span class="block text-[11px] text-[var(--text-muted)]">{{ $visit->company_name }}</span>@endif
                        @if ($visit->headcount() > 1)<span class="block text-[11px] text-[var(--text-muted)]">party of {{ $visit->headcount() }}</span>@endif
                    </td>
                    <td>{{ $visit->host?->fullName() ?? '—' }}</td>
                    <td class="text-xs text-[var(--text-muted)]">{{ $visit->location?->name ?? '—' }}</td>
                    <td class="whitespace-nowrap text-xs">
                        @if ($visit->checked_in_at)
                            in {{ $visit->checked_in_at->format('j M H:i') }}
                            @if ($visit->checked_out_at)
                                <span class="block text-[var(--text-muted)]">out {{ $visit->checked_out_at->format('H:i') }}@if ($visit->auto_closed) (assumed)@endif</span>
                            @endif
                        @elseif ($visit->expected_from)
                            expected {{ $visit->expected_from->format('j M H:i') }}
                        @else
                            —
                        @endif
                    </td>
                    <td>@if ($visit->status)<x-badge :tone="$visit->status->tone()">{{ $visit->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
