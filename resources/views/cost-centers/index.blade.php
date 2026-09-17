<x-layouts.app title="Cost centers">
<x-page-header title="Cost centers" description="The accountable units and projects that own budgets.">
    @if ($canCreate)
        <x-slot:actions>
            <a href="{{ route('cost-centers.create') }}" class="btn btn-primary btn-sm">Add a cost center</a>
        </x-slot:actions>
    @endif
</x-page-header>
<form method="GET" class="card mb-5 flex gap-3 p-4"><x-input name="q" :value="$filters['q']" placeholder="Code or name"/><x-select name="active" :options="['1'=>'Active','0'=>'Inactive']" :selected="$filters['active']" placeholder="All"/><button class="btn btn-secondary">Filter</button></form>
<div class="card overflow-hidden"><table class="table"><thead><tr><th>Cost center</th><th>Scope</th><th>Manager</th><th class="text-right">Budgets</th><th>Active</th></tr></thead><tbody>@forelse($centers as $center)<tr><td><a href="{{ route('cost-centers.show',$center) }}" class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $center->name }}</a><span class="block font-mono text-xs text-[var(--text-muted)]">{{ $center->code }}</span></td><td>{{ $center->project?->name ?? $center->orgUnit?->name ?? 'Organization-wide' }}</td><td>{{ $center->manager?->name ?? '—' }}</td><td class="text-right">{{ $center->budgets_count }}</td><td><x-badge :tone="$center->is_active ? 'success':'neutral'">{{ $center->is_active ? 'Active':'Inactive' }}</x-badge></td></tr>@empty<tr><td colspan="5" class="py-10 text-center">No cost centers match.</td></tr>@endforelse</tbody></table>@if($centers->hasPages())<div class="p-4">{{ $centers->links() }}</div>@endif</div></x-layouts.app>
