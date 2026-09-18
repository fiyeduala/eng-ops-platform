<x-layouts.app title="Movements">
    <x-page-header title="Movements" description="Promotions, transfers, secondments and acting appointments — what is agreed, and what is still to take effect.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('employee-movements.create') }}" class="btn btn-primary btn-sm">Propose a movement</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @foreach (['open' => 'Live', 'all' => 'All I can see'] as $key => $label)
            <a href="{{ route('employee-movements.index', ['view' => $key]) }}" class="btn btn-sm {{ $filters['view'] === $key ? 'btn-secondary' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <input type="hidden" name="view" value="{{ $filters['view'] }}">
        <div class="min-w-48">
            <label class="label" for="kind">Kind</label>
            <x-select name="kind" :options="$kinds" :selected="$filters['kind'] ?: null" placeholder="Any kind"/>
        </div>
        <button class="btn btn-secondary btn-sm">Apply</button>
        @if ($filters['kind'])<a href="{{ route('employee-movements.index', ['view' => $filters['view']]) }}" class="btn btn-ghost btn-sm">Clear</a>@endif
    </form>

    <x-register.filters route="employee-movements.index" :filters="$filters" :statuses="$statuses" placeholder="Number or name"/>

    <x-register.table :records="$movements" icon="arrow-right" title="No movements"
                      description="Nobody has been proposed for a move you can see. Promotions and transfers are restricted records — an empty register is the usual view.">
        <x-slot:head><tr><th>Number</th><th>Person</th><th>Kind</th><th>Effective</th><th>Moving to</th><th>Status</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($movements as $movement)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('employee-movements.show', $movement) }}">{{ $movement->number }}</a></td>
                    <td>{{ $movement->employee?->fullName() ?? '—' }}</td>
                    <td>{{ $movement->kindLabel() }}</td>
                    <td class="whitespace-nowrap">
                        {{ $movement->effective_on?->format('j M Y') }}
                        @if ($movement->isDueToApply())
                            <span class="block text-[11px] text-[var(--text-muted)]">due to take effect</span>
                        @endif
                    </td>
                    {{-- The destination only. The full before-and-after needs four more relations
                         per row and belongs on the record page, not on a paginated register (§89). --}}
                    <td class="text-xs text-[var(--text-muted)]">
                        {{ implode(' · ', array_filter([$movement->toPosition?->title, $movement->toOrgUnit?->name])) ?: 'No change recorded' }}
                    </td>
                    <td>@if ($movement->status)<x-badge :tone="$movement->status->tone()">{{ $movement->status->label }}</x-badge>@endif</td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
