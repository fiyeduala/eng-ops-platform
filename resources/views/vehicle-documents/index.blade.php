{{--
    The papers that make a fleet legal, and the dates they stop doing so.

    THE DEFAULT TAB SHOWS EXPIRING **AND ALREADY EXPIRED**, which is the one place in this platform
    where a warning window is deliberately widened rather than narrowed. `expiringWithin()` on its
    own excludes what has already lapsed — right for an e-mail, wrong for a worklist. A tab that
    drops a certificate at the exact moment it starts to matter is how people end up driving
    uninsured.

    THERE IS NO STATUS COLUMN, because there is no status. Expiry is a date compared against today,
    asked afresh every time. Nothing has to run for a lapsed certificate to read as lapsed, which is
    the point: a cron that stops costs the company its warnings and can never make an expired
    document look valid.

    Expects: $documents, $vehicles, $types, $views, $warningDays, $filters, $canCreate
--}}
<x-layouts.app title="Vehicle papers">

    <x-page-header
        title="Vehicle papers"
        description="Insurance, roadworthiness, permits and licences — and when each of them runs out.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('vehicle-documents.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> File a certificate
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="vehicle-documents.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="vehicle-documents.index" :filters="$filters"
                        placeholder="Certificate number or issuer">
        <x-slot:extra>
            <div>
                <label for="vehicle" class="label">Vehicle</label>
                <x-select name="vehicle" :options="$vehicles" :selected="$filters['vehicle']"
                          placeholder="Any vehicle"/>
            </div>

            <div>
                <label for="type" class="label">Certificate</label>
                <x-select name="type" :options="$types" :selected="$filters['type']"
                          placeholder="Any certificate"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$documents" icon="file-text"
                      title="No certificates"
                      description="Nothing your access reaches. A certificate follows the vehicle it belongs to, so papers for another site's trucks may be out of view.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('vehicle-documents.create') }}" class="btn btn-primary btn-sm">File a certificate</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Certificate</th>
                <th>Vehicle</th>
                <th>Number</th>
                <th>Issued</th>
                <th>Expires</th>
                <th>Scan</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($documents as $document)
                <tr>
                    <td>
                        <a href="{{ route('vehicle-documents.show', $document) }}"
                           class="font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $document->typeLabel() }}
                        </a>
                        @if ($document->issuer)
                            <span class="block text-xs text-[var(--text-muted)]">{{ $document->issuer }}</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($document->vehicle)
                            <a href="{{ route('vehicles.show', $document->vehicle) }}"
                               class="font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                                {{ $document->vehicle->registration_number }}
                            </a>
                        @else
                            <span class="text-sm text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="font-mono text-sm text-[var(--text-muted)]">
                        {{ $document->document_number ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        {{ $document->issued_on?->format('j M Y') ?: '—' }}
                    </td>

                    <td class="whitespace-nowrap text-sm">
                        @if (! $document->expires_on)
                            {{-- Spelled out. A blank here would read as "nobody has filled this in",
                                 which is a different fact from "this certificate does not lapse". --}}
                            <span class="text-[var(--text-muted)]">Never expires</span>
                        @elseif ($document->hasExpired())
                            <span class="font-medium text-rose-600">
                                {{ $document->expires_on->format('j M Y') }}
                            </span>
                            <x-badge tone="danger">Expired</x-badge>
                        @elseif ($document->expiresWithin($warningDays))
                            {{ $document->expires_on->format('j M Y') }}
                            <x-badge tone="warning">{{ $document->daysRemaining() }} days</x-badge>
                        @else
                            {{ $document->expires_on->format('j M Y') }}
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($document->hasFile())
                            <a href="{{ route('vehicle-documents.download', $document) }}"
                               class="btn btn-ghost btn-sm">
                                <x-icon name="download" class="h-3.5 w-3.5"/> Scan
                            </a>
                        @else
                            <span class="text-sm text-[var(--text-muted)]">—</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
