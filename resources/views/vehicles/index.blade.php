{{--
    The fleet.

    THIS REGISTER OPENS ON `papers_due` and no other register in the platform opens on a date. The
    reason is narrow: a lorry with a lapsed roadworthiness certificate is not a piece of admin, it is
    a vehicle that must not leave the yard this morning. The window is the company's own setting —
    the same figure the nightly sweep reads — so this tab and that e-mail can never disagree.

    THERE IS NO STATUS COLUMN OF ITS OWN. A vehicle's status is its ASSET's status, shown as such,
    because a truck that is "under maintenance" is under maintenance as a thing the company owns, not
    as a plate. Everything else on this row — custody, project, retirement — is likewise the asset's.

    NO DELETE ANYWHERE ON THIS REGISTER. A lorry leaves the fleet by being RETIRED as an asset, which
    keeps its custody trail, its job cards and its fuel history attached to it. Deleting the plate
    would leave the machine behind with a hole where its history was.

    Expects: $vehicles, $types, $views, $filters, $canCreate
--}}
<x-layouts.app title="Fleet">

    <x-page-header
        title="Fleet"
        description="Plates, drivers, papers and what the tank has cost.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('vehicles.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Attach a plate
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="vehicles.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="vehicles.index" :filters="$filters"
                        placeholder="Plate, VIN or asset code">
        <x-slot:extra>
            <div>
                <label for="type" class="label">Kind</label>
                <x-select name="type" :options="$types" :selected="$filters['type']"
                          placeholder="Any kind"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$vehicles" icon="truck"
                      title="No vehicles"
                      description="Nothing your access reaches. A vehicle follows the asset it is attached to, so plant belonging to another site may be out of view.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('vehicles.create') }}" class="btn btn-primary btn-sm">Attach a plate</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Plate</th>
                <th>Kind</th>
                <th>Driver</th>
                <th>Odometer</th>
                @if ($canSeeDocuments)
                    <th>Papers</th>
                @endif
                <th>Asset status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($vehicles as $vehicle)
                <tr>
                    <td>
                        <a href="{{ route('vehicles.show', $vehicle) }}"
                           class="font-mono font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $vehicle->registration_number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $vehicle->asset?->name }}
                        </span>
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $vehicle->vehicle_type ?: '—' }}
                    </td>

                    <td class="text-sm">
                        @if ($vehicle->assignedDriver)
                            <span class="text-[var(--text-body)]">{{ $vehicle->assignedDriver->name }}</span>
                        @else
                            {{-- "Nobody" rather than a dash, for the same reason it is spelled out on
                                 the asset register: an unnamed driver is a thing to fix, not a blank. --}}
                            <span class="text-[var(--text-muted)]">Nobody</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap font-mono text-sm text-[var(--text-muted)]">
                        @if ($vehicle->odometer() !== null)
                            {{ number_format($vehicle->odometer()) }}
                            <span class="text-xs">{{ $vehicle->odometer_unit }}</span>
                        @else
                            —
                        @endif
                    </td>

                    @if ($canSeeDocuments)
                        <td class="whitespace-nowrap">
                            {{-- Deliberately a count and a tone, not a list. WHICH certificate has
                                 lapsed is on the vehicle's own page; this says how many and not what,
                                 and the whole column is absent for somebody who may see the lorry but
                                 not its papers (§61). --}}
                            @php $due = (int) ($vehicle->documents_due_count ?? 0); @endphp
                            @if ($due > 0)
                                <x-badge tone="danger">{{ $due }} due</x-badge>
                            @else
                                <x-badge tone="success">In order</x-badge>
                            @endif
                        </td>
                    @endif

                    <td class="whitespace-nowrap">
                        @if ($vehicle->asset?->status)
                            <x-badge :tone="$vehicle->asset->status->tone()">
                                {{ $vehicle->asset->status->label }}
                            </x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
