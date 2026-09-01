{{--
    The client register.

    IT OPENS ON ACTIVE, and the second tab is "my accounts" rather than anything clever. A commercial
    manager asks two questions of this screen — who are we working for, and which of them are mine —
    and a register that answers both in one click is doing its whole job.

    PROJECTS AND CONTACTS ARE COUNTED, NOT LISTED. The count is what tells you whether a client is a
    real trading relationship or a name somebody typed in once; the list belongs on the client's own
    page, where it can be filtered by what the reader may actually open (§61).

    Expects: $clients, $statuses, $types, $industries, $views, $filters, $canCreate
--}}
<x-layouts.app title="Clients">

    <x-page-header
        title="Clients"
        description="Who we work for.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('clients.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Add a client
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="clients.index" :views="$views" :current="$filters['view']"/>

    {{-- A client owns projects rather than belonging to one, so the shared bar's project select is
         dropped by passing nothing to it. --}}
    <x-register.filters route="clients.index" :filters="$filters" :projects="[]"
                        :statuses="$statuses" placeholder="Name, code, number or city">
        <x-slot:extra>
            <div>
                <label for="client_type" class="label">Type</label>
                <x-select name="client_type" :options="$types"
                          :selected="$filters['client_type']" placeholder="Any type"/>
            </div>
            <div>
                <label for="industry" class="label">Industry</label>
                <x-select name="industry" :options="$industries"
                          :selected="$filters['industry']" placeholder="Any industry"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$clients" icon="briefcase"
                      title="No clients"
                      description="Either nothing matches these filters, or the clients that do sit in units your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('clients.create') }}" class="btn btn-primary btn-sm">Add a client</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Client</th>
                <th>Type</th>
                <th>Industry</th>
                <th>Account owner</th>
                <th class="text-right">Projects</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($clients as $client)
                <tr>
                    <td>
                        <a href="{{ route('clients.show', $client) }}"
                           @class([
                               'font-medium hover:text-brand-600',
                               'text-[var(--text-strong)]' => $client->is_active,
                               'text-[var(--text-muted)]' => ! $client->is_active,
                           ])>
                            {{ $client->name }}
                        </a>
                        @unless ($client->is_active)
                            <x-badge tone="neutral">Inactive</x-badge>
                        @endunless
                        <span class="block font-mono text-xs text-[var(--text-muted)]">
                            {{ $client->code ?: $client->number }}
                            @if ($client->city) · {{ $client->city }} @endif
                            @if ($client->contacts_count) · {{ $client->contacts_count }} {{ Str::plural('contact', $client->contacts_count) }} @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                        {{ $client->client_type ? ($types[$client->client_type] ?? Str::headline($client->client_type)) : '—' }}
                    </td>

                    <td class="text-sm text-[var(--text-muted)]">
                        {{ $client->industry ? ($industries[$client->industry] ?? Str::headline($client->industry)) : '—' }}
                    </td>

                    {{-- Unowned is a state worth seeing rather than a blank. An account nobody is
                         named against is one nobody is chasing. --}}
                    <td class="whitespace-nowrap text-sm">
                        @if ($client->relationshipOwner)
                            <span class="text-[var(--text-body)]">{{ $client->relationshipOwner->name }}</span>
                        @else
                            <span class="text-amber-600">Nobody</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap text-right text-sm text-[var(--text-muted)]">
                        {{ $client->projects_count }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($client->status)
                            <x-badge :tone="$client->status->tone()">{{ $client->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">New</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
