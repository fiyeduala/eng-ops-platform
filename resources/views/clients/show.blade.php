{{--
    One client.

    THE PROJECTS AND CONTRACTS LISTS ARE THE §90 PAYOFF. Before this register a project carried a
    typed client name and nothing joined up; now standing on the client answers "what are we doing
    for them" and "what have we signed" without anybody searching. Both lists arrive already filtered
    by what THIS READER may open — a relationship manager sees the account without a roll-call of
    jobs they have no reach into, and a contract stays confidential by default (§61, §68).

    THE ACCOUNT OWNER HAS ITS OWN FORM AND ITS OWN PERMISSION. It is not vanity: that column is what
    Client::accessAssigneeColumns() reads, so assigning it grants sight of the record. On the edit
    form it would let a user with "assigned to me" reach widen themselves across the register; here
    it sits behind `client.manage` (§84).

    Expects: $client, $projects, $contracts, $timeline, $transitions, $workflowInstance, $people,
             $canEdit, $canDelete, $canAssignOwner
--}}
<x-layouts.app :title="$client->name">

    <x-page-header :title="$client->name" :description="$client->number.($client->code ? ' · '.$client->code : '')">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('clients.edit', $client) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @error('relationship_owner_id') <p class="error mb-4">{{ $message }}</p> @enderror

    @unless ($client->is_active)
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm text-[var(--text-body)]">
                Marked inactive. The record stays readable and stops appearing in the pickers on new
                projects and contracts.
            </p>
        </div>
    @endunless

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The organization</h3>
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Registered name" :value="$client->legal_name"/>
                        <x-register.fact label="Type"
                                         :value="$client->client_type ? Str::headline($client->client_type) : null"/>
                        <x-register.fact label="Industry"
                                         :value="$client->industry ? Str::headline($client->industry) : null"/>
                        <x-register.fact label="Registration number" :value="$client->registration_no" mono/>
                        <x-register.fact label="Tax identifier" :value="$client->tax_id" mono/>

                        <x-register.fact label="Email">
                            @if ($client->email)
                                <a href="mailto:{{ $client->email }}" class="hover:text-brand-600">{{ $client->email }}</a>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </x-register.fact>

                        <x-register.fact label="Phone" :value="$client->phone"/>

                        <x-register.fact label="Website">
                            @if ($client->website)
                                <a href="{{ $client->website }}" target="_blank" rel="noopener noreferrer"
                                   class="hover:text-brand-600">{{ $client->website }}</a>
                            @else
                                <span class="text-[var(--text-muted)]">—</span>
                            @endif
                        </x-register.fact>

                        <x-register.fact label="Address"
                                         :value="collect([$client->address, $client->city, $client->state, $client->country])->filter()->join(', ') ?: null"/>
                    </dl>

                    @if ($client->notes)
                        <div class="mt-4 border-t border-[var(--surface-border)] pt-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Notes</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $client->notes }}</p>
                        </div>
                    @endif
                </div>
            </div>

            @include('partials.party-contacts', [
                'contacts' => $client->contacts,
                'canEdit' => $canEdit,
                'storeRoute' => route('clients.contacts.store', $client),
                'updateRoutePrefix' => 'client-contacts',
                'destroyRoutePrefix' => 'client-contacts',
                'partyName' => $client->name,
            ])

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Projects</h3>
                    <span class="text-xs text-[var(--text-muted)]">
                        {{ $projects->count() }} you can open
                    </span>
                </div>
                @if ($projects->isEmpty())
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            No projects for this client that you can open.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Project</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($projects as $project)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <a href="{{ route('projects.show', $project) }}"
                                               class="font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                                                {{ $project->code }}
                                            </a>
                                        </td>
                                        <td>{{ $project->name }}</td>
                                        <td class="whitespace-nowrap">
                                            @if ($project->status)
                                                <x-badge :tone="$project->status->tone()">{{ $project->status->label }}</x-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Contracts</h3>
                </div>
                @if ($contracts->isEmpty())
                    <div class="p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            No contracts with this client that you can open.
                        </p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Number</th>
                                    <th>Title</th>
                                    <th>Ends</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contracts as $contract)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            <a href="{{ route('contracts.show', $contract) }}"
                                               class="font-mono text-sm text-[var(--text-strong)] hover:text-brand-600">
                                                {{ $contract->number }}
                                            </a>
                                        </td>
                                        <td>{{ $contract->title }}</td>
                                        <td class="whitespace-nowrap text-sm text-[var(--text-muted)]">
                                            {{ $contract->ends_on?->format('j M Y') ?: '—' }}
                                        </td>
                                        <td class="whitespace-nowrap">
                                            @if ($contract->status)
                                                <x-badge :tone="$contract->status->tone()">{{ $contract->status->label }}</x-badge>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The account</h3>
                    @if ($client->status)
                        <x-badge :tone="$client->status->tone()">{{ $client->status->label }}</x-badge>
                    @endif
                </div>
                <div class="p-5">
                    <dl class="divide-y divide-[var(--surface-border)]">
                        <x-register.fact label="Account owner">
                            @if ($client->relationshipOwner)
                                {{ $client->relationshipOwner->name }}
                            @else
                                <span class="text-amber-600">Nobody named</span>
                            @endif
                        </x-register.fact>
                        <x-register.fact label="Payment terms"
                                         :value="$client->payment_terms_days !== null ? $client->payment_terms_days.' days' : null"/>
                        <x-register.fact label="Credit limit"
                                         :value="$client->credit_limit !== null ? number_format((float) $client->credit_limit, 2) : null"
                                         mono/>
                        <x-register.fact label="Owning unit" :value="$client->orgUnit?->name"/>
                        <x-register.fact label="Confidentiality" :value="$client->confidentiality?->label()"/>
                        <x-register.fact label="Added by" :value="$client->creator?->name"/>
                    </dl>
                </div>

                @if ($canAssignOwner)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <form method="POST" action="{{ route('clients.owner.assign', $client) }}" class="space-y-3">
                            @csrf
                            <p class="text-sm font-medium text-[var(--text-strong)]">Hand the account over</p>
                            <p class="text-xs text-[var(--text-muted)]">
                                The named owner can reach this client and everything scoped to it.
                                Leaving it unassigned is a legitimate answer — it just means nobody
                                is on the hook for the relationship.
                            </p>
                            <x-select name="relationship_owner_id" :options="$people"
                                      :selected="$client->relationship_owner_id"
                                      placeholder="Nobody"/>
                            <button type="submit" class="btn btn-secondary btn-sm">Assign</button>
                        </form>
                    </div>
                @endif

                <x-register.transitions :action="route('clients.transition', $client)"
                                        :transitions="$transitions" noun="client"/>
            </div>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this client was added."/>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Projects and contracts already pointed at this client keep pointing at
                            nothing. If the relationship has simply ended, mark the client inactive
                            instead — it drops out of the pickers and the history stays legible.
                        </p>
                        <x-delete-form :action="route('clients.destroy', $client)"
                                       label="Delete this client"
                                       :confirm="'Delete '.$client->name.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
