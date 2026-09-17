<x-layouts.app title="Leave types">
    <x-page-header title="Leave types" description="The leave your company grants and its rules. Changing a type never recounts leave already booked.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('leave-types.create') }}" class="btn btn-primary btn-sm">Add a leave type</a>
            </x-slot:actions>
        @endif
    </x-page-header>
    @include('finance.errors')
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Code</th><th>Name</th><th class="text-right">Days a year</th><th class="text-right">Carry over</th><th>Paid</th><th>Approval</th><th>Active</th></tr></thead>
            <tbody>
                @forelse ($types as $type)
                    <tr>
                        <td class="font-mono">
                            @can('update', $type)
                                <a href="{{ route('leave-types.edit', $type) }}" class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $type->code }}</a>
                            @else
                                {{ $type->code }}
                            @endcan
                        </td>
                        <td>{{ $type->name }}</td>
                        <td class="text-right font-mono">{{ $type->days_per_year === null ? 'Not counted' : rtrim(rtrim((string) $type->days_per_year, '0'), '.') }}</td>
                        <td class="text-right font-mono">{{ rtrim(rtrim((string) $type->carry_over_limit, '0'), '.') ?: '0' }}</td>
                        <td>{{ $type->is_paid ? 'Yes' : 'No' }}</td>
                        <td>{{ $type->requires_approval ? 'Required' : 'Booked directly' }}</td>
                        <td><x-badge :tone="$type->is_active ? 'success' : 'neutral'">{{ $type->is_active ? 'Active' : 'Inactive' }}</x-badge></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center">No leave types yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
