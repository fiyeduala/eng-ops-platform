<x-layouts.app title="Joining and leaving">
    <x-page-header title="Joining and leaving" description="Everything a new starter is owed, and everything a leaver hands back."/>
    <form method="GET" action="{{ route('employee-checklists.index') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <x-field label="Kind" name="kind" class="min-w-44">
            <x-select name="kind" :options="collect($kinds)->mapWithKeys(fn ($k) => [$k => ucfirst($k)])->all()" :selected="$filters['kind']" placeholder="Both"/>
        </x-field>
        <x-field label="Show" name="view" class="min-w-44">
            <x-select name="view" :options="['open' => 'Still open', 'all' => 'All']" :selected="$filters['view']"/>
        </x-field>
        <button class="btn btn-secondary btn-sm">Filter</button>
        <a href="{{ route('employee-checklists.index') }}" class="btn btn-ghost btn-sm">Clear</a>
    </form>
    <x-register.table :records="$checklists" icon="check" title="No checklists" description="No joining or leaving list within your scope matches these filters.">
        <x-slot:head><tr><th>Person</th><th>Kind</th><th>List</th><th>Started</th><th>Due</th><th class="text-right">Done</th><th>State</th></tr></x-slot:head>
        <x-slot:rows>
            @foreach ($checklists as $checklist)
                <tr>
                    <td><a class="font-medium text-[var(--text-strong)] hover:text-brand-600" href="{{ route('employee-checklists.show', $checklist) }}">{{ $checklist->employee?->fullName() ?? '—' }}</a></td>
                    <td>{{ ucfirst($checklist->kind) }}</td>
                    <td>{{ $checklist->template?->name ?? 'Ad hoc' }}</td>
                    <td class="whitespace-nowrap">{{ $checklist->started_on?->format('j M Y') }}</td>
                    <td class="whitespace-nowrap">{{ $checklist->due_on?->format('j M Y') ?? '—' }}</td>
                    <td class="text-right font-mono">{{ $checklist->done_items_count }}/{{ $checklist->items_count }}</td>
                    <td>
                        @if ($checklist->isComplete())
                            <x-badge tone="success">Complete</x-badge>
                        @elseif ($checklist->isOverdue())
                            <x-badge tone="danger">Overdue</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>
</x-layouts.app>
