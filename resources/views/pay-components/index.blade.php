<x-layouts.app title="Pay structure">
    <x-page-header title="Pay structure"
                   description="How this company pays people — every allowance, tax, contribution and deduction, in the order they are calculated.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('pay-components.create') }}" class="btn btn-primary btn-sm">Add a component</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{--
        Said plainly on the page, because it is the thing administrators most often expect to find
        and will otherwise go looking for: there is no country here and there never will be.
    --}}
    <div class="card mb-6 p-4">
        <p class="text-xs text-[var(--text-muted)]">
            Nothing on this page ships with the platform. Your tax authority's rates, your pension scheme, your
            allowances — they are rows you type, which is why two companies in two countries run on the same payroll
            without either of them being a special case. Change one and runs already approved keep the figures they
            were calculated with.
        </p>
    </div>

    @if ($components->isEmpty())
        <x-empty-state icon="adjustments" title="No pay structure yet"
                       description="Start with basic salary — a percentage component on the basic basis at 100% — then add the taxes and contributions your company actually operates. A payroll run with no components calculates nothing."/>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead>
                    <tr><th>Seq</th><th>Code</th><th>Name</th><th>Kind</th><th>How</th><th>Flags</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($components as $component)
                        <tr class="{{ $component->is_active ? '' : 'opacity-60' }}">
                            <td class="tabular-nums">{{ $component->sequence }}</td>
                            <td class="font-medium text-[var(--text-strong)]">{{ $component->code }}</td>
                            <td>
                                {{ $component->name }}
                                @unless ($component->is_active)
                                    <span class="block text-[11px] text-[var(--text-muted)]">retired</span>
                                @endunless
                            </td>
                            <td>
                                <x-badge :tone="$component->kind === 'deduction' ? 'warning' : ($component->kind === 'relief' ? 'brand' : 'neutral')">
                                    {{ $kinds[$component->kind] ?? $component->kind }}
                                </x-badge>
                            </td>
                            <td class="text-xs text-[var(--text-muted)]">
                                {{ \App\Models\PayComponent::METHODS[$component->method] ?? $component->method }}
                                @if ($component->method === 'banded')
                                    · {{ $component->bands_count }} {{ str('band')->plural($component->bands_count) }}
                                @elseif ($component->method === 'percent')
                                    · {{ rtrim(rtrim((string) $component->rate, '0'), '.') }}% of {{ \App\Models\PayComponent::BASES[$component->basis] ?? $component->basis }}
                                @elseif ($component->method === 'hourly')
                                    · {{ number_format((float) $component->rate, 2) }} per hour
                                @else
                                    · {{ number_format((float) $component->amount, 2) }}
                                @endif
                            </td>
                            <td class="text-[11px] text-[var(--text-muted)]">
                                @if ($component->applies_to_all)<span class="block">everybody</span>@endif
                                @if ($component->is_taxable)<span class="block">taxable</span>@endif
                                @if ($component->is_pensionable)<span class="block">pensionable</span>@endif
                                @if ($component->reduces_taxable)<span class="block">reduces taxable</span>@endif
                            </td>
                            <td class="text-right">
                                @can('update', $component)
                                    <a href="{{ route('pay-components.edit', $component) }}" class="btn btn-ghost btn-sm">Edit</a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
