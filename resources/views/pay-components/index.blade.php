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
                    {{--
                        NOT `$component`. Blade shadows that name inside every component slot with the
                        component instance itself, so `{{ $payComponent->kind }}` one line below — which is
                        inside an <x-badge> slot — would read the AnonymousComponent and fatal.
                    --}}
                    @foreach ($components as $payComponent)
                        <tr class="{{ $payComponent->is_active ? '' : 'opacity-60' }}">
                            <td class="tabular-nums">{{ $payComponent->sequence }}</td>
                            <td class="font-medium text-[var(--text-strong)]">{{ $payComponent->code }}</td>
                            <td>
                                {{ $payComponent->name }}
                                @unless ($payComponent->is_active)
                                    <span class="block text-[11px] text-[var(--text-muted)]">retired</span>
                                @endunless
                            </td>
                            <td>
                                <x-badge :tone="$payComponent->kind === 'deduction' ? 'warning' : ($payComponent->kind === 'relief' ? 'brand' : 'neutral')">
                                    {{ $kinds[$payComponent->kind] ?? $payComponent->kind }}
                                </x-badge>
                            </td>
                            <td class="text-xs text-[var(--text-muted)]">
                                {{ \App\Models\PayComponent::METHODS[$payComponent->method] ?? $payComponent->method }}
                                @if ($payComponent->method === 'banded')
                                    · {{ $payComponent->bands_count }} {{ str('band')->plural($payComponent->bands_count) }}
                                @elseif ($payComponent->method === 'percent')
                                    · {{ rtrim(rtrim((string) $payComponent->rate, '0'), '.') }}% of {{ \App\Models\PayComponent::BASES[$payComponent->basis] ?? $payComponent->basis }}
                                @elseif ($payComponent->method === 'hourly')
                                    · {{ number_format((float) $payComponent->rate, 2) }} per hour
                                @else
                                    · {{ number_format((float) $payComponent->amount, 2) }}
                                @endif
                            </td>
                            <td class="text-[11px] text-[var(--text-muted)]">
                                @if ($payComponent->applies_to_all)<span class="block">everybody</span>@endif
                                @if ($payComponent->is_taxable)<span class="block">taxable</span>@endif
                                @if ($payComponent->is_pensionable)<span class="block">pensionable</span>@endif
                                @if ($payComponent->reduces_taxable)<span class="block">reduces taxable</span>@endif
                            </td>
                            <td class="text-right">
                                @can('update', $payComponent)
                                    <a href="{{ route('pay-components.edit', $payComponent) }}" class="btn btn-ghost btn-sm">Edit</a>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
