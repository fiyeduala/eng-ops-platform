{{--
    The contract register — both directions in one place.

    IT SORTS BY END DATE, NEAREST FIRST, with undated contracts last. Every other register in this
    application sorts newest-first because the newest record is the one being worked on; here the
    urgent record is the one running out. A framework agreement that lapsed last Tuesday is the
    reason a purchase order cannot be raised this morning.

    THE VALUE COLUMN IS NOT ALWAYS DRAWN. `$canViewFinancials` is asked once, of the register rather
    than of each row, because financial visibility is a permission and not a property of a particular
    contract. Where it is false the column is absent — not blank, not dashed — because a column of
    dashes still tells the reader that thirty contracts have values and that somebody else can read
    them (§6, §61).

    EXPIRY IS A DATE QUERY, NOT A STATUS. Contracts pass their end date whether or not anybody
    clicks anything, so "expiring soon" is computed against a window the company sets in Settings.

    Expects: $contracts, $statuses, $projects, $types, $warningDays, $views, $filters,
             $canViewFinancials, $canCreate
--}}
<x-layouts.app title="Contracts">

    <x-page-header
        title="Contracts"
        description="What was agreed, with whom, and until when.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('contracts.create', ['direction' => 'client']) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Client contract
                </a>
                <a href="{{ route('contracts.create', ['direction' => 'vendor']) }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Vendor contract
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="contracts.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="contracts.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number, title, party or their reference">
        <x-slot:extra>
            <div>
                <label for="direction" class="label">Direction</label>
                <x-select name="direction"
                          :options="['client' => 'With clients (income)', 'vendor' => 'With vendors (spend)']"
                          :selected="$filters['direction']" placeholder="Both directions"/>
            </div>
            <div>
                <label for="contract_type" class="label">Type</label>
                <x-select name="contract_type" :options="$types"
                          :selected="$filters['contract_type']" placeholder="Any type"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$contracts" icon="gavel"
                      title="No contracts"
                      description="Contracts are confidential by default, so this can also mean there are contracts your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('contracts.create') }}" class="btn btn-primary btn-sm">Register a contract</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Contract</th>
                <th>With</th>
                <th>Project</th>
                @if ($canViewFinancials)
                    <th class="text-right">Value</th>
                    <th class="text-right">Retention held</th>
                @endif
                <th>Ends</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($contracts as $contract)
                <tr>
                    <td>
                        <a href="{{ route('contracts.show', $contract) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $contract->title }}
                        </a>
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $contract->number }}
                            @if ($contract->reference) · their ref {{ $contract->reference }} @endif
                        </span>
                    </td>

                    {{-- The direction badge carries more meaning than the party name on its own:
                         the same company can appear on both sides of the register. --}}
                    <td>
                        <span class="text-sm text-[var(--text-body)]">{{ $contract->partyLabel() }}</span>
                        <span class="block text-xs">
                            @if ($contract->isClientSide())
                                <span class="text-emerald-600">Client · income</span>
                            @else
                                <span class="text-[var(--text-muted)]">Vendor · spend</span>
                            @endif
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $contract->project?->name ?: '—' }}
                    </td>

                    @if ($canViewFinancials)
                        <td class="whitespace-nowrap text-right font-mono text-sm text-[var(--text-body)]">
                            @if ($contract->value !== null)
                                @if ($contract->currency)
                                    <span class="text-xs text-[var(--text-muted)]">{{ $contract->currency }}</span>
                                @endif
                                {{ number_format((float) $contract->value, 2) }}
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </td>

                        <td class="whitespace-nowrap text-right font-mono text-sm">
                            @if ((float) $contract->retention_held > 0)
                                <span class="text-amber-600">{{ number_format((float) $contract->retention_held, 2) }}</span>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </td>
                    @endif

                    <td class="whitespace-nowrap text-sm">
                        @if ($contract->ends_on)
                            <span @class([
                                'font-medium text-rose-600' => $contract->hasExpired(),
                                'font-medium text-amber-600' => $contract->expiresWithin($warningDays),
                                'text-[var(--text-muted)]' => ! $contract->hasExpired()
                                    && ! $contract->expiresWithin($warningDays),
                            ])>
                                {{ $contract->ends_on->format('j M Y') }}
                            </span>
                            @if ($contract->hasExpired())
                                <span class="block text-xs text-rose-600">Expired</span>
                            @endif
                        @else
                            <span class="text-[var(--text-muted)]">Open-ended</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($contract->status)
                            <x-badge :tone="$contract->status->tone()">{{ $contract->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Draft</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
