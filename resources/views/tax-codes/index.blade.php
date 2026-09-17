<x-layouts.app title="Tax codes">
    <x-page-header title="Tax codes" description="The company's own rates. Each expense and invoice keeps a copy of the rate it was raised with.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('tax-codes.create') }}" class="btn btn-primary btn-sm">Add a tax code</a>
            </x-slot:actions>
        @endif
    </x-page-header>
    @include('finance.errors')
    <form method="GET" class="card mb-5 flex gap-3 p-4"><x-input name="q" :value="$filters['q']" placeholder="Code or name"/><button class="btn btn-secondary">Filter</button></form>
    <div class="card overflow-hidden">
        <table class="table">
            <thead><tr><th>Code</th><th>Name</th><th class="text-right">Rate</th><th>Applies to</th><th>Recoverable</th><th>Active</th></tr></thead>
            <tbody>
                @forelse ($codes as $code)
                    <tr>
                        <td class="font-mono">
                            @can('update', $code)
                                <a href="{{ route('tax-codes.edit', $code) }}" class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $code->code }}</a>
                            @else
                                {{ $code->code }}
                            @endcan
                        </td>
                        <td>{{ $code->name }}</td>
                        <td class="text-right font-mono">{{ rtrim(rtrim(number_format((float) $code->rate, 4), '0'), '.') }}%</td>
                        <td>{{ \App\Models\TaxCode::APPLIES_TO[$code->applies_to] ?? $code->applies_to }}</td>
                        <td>{{ $code->is_recoverable ? 'Yes' : 'No' }}</td>
                        <td><x-badge :tone="$code->is_active ? 'success' : 'neutral'">{{ $code->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-10 text-center">No tax codes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($codes->hasPages())<div class="p-4">{{ $codes->links() }}</div>@endif
    </div>
</x-layouts.app>
