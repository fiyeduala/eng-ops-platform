<x-layouts.app :title="$account->code">
    <x-page-header :title="$account->name" :description="$account->code">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('petty-cash.edit', $account) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')
    @php($headroom = $account->headroom())
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Balance ({{ $account->currency }})</p><p class="mt-1 font-mono text-xl font-semibold">{{ number_format((float) $account->balance, 2) }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Float</p><p class="mt-1 font-mono text-xl font-semibold">{{ (float) $account->float_amount > 0 ? number_format((float) $account->float_amount, 2) : 'No ceiling' }}</p></div>
                <div class="card p-4"><p class="text-xs text-[var(--text-muted)]">Room to replenish</p><p class="mt-1 font-mono text-xl font-semibold">{{ $headroom === null ? '—' : number_format((float) $headroom, 2) }}</p></div>
            </div>
            @unless ($account->is_active)
                <div class="card border-amber-300 p-4 text-sm">This tin is closed. No cash can move through it.</div>
            @endunless

            <div class="card overflow-hidden">
                <div class="card-header"><h2 class="card-title">Vouchers</h2></div>
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr><th>Voucher</th><th>Date</th><th>Details</th><th class="text-right">Amount</th><th class="text-right">Balance</th>@if ($canReverse)<th></th>@endif</tr></thead>
                        <tbody>
                            @forelse ($transactions as $txn)
                                <tr>
                                    <td class="whitespace-nowrap"><span class="font-mono">{{ $txn->number }}</span><span class="block text-xs text-[var(--text-muted)]">{{ \App\Models\PettyCashTransaction::TYPES[$txn->type] ?? $txn->type }}</span></td>
                                    <td class="whitespace-nowrap">{{ $txn->transacted_on?->format('j M Y') }}</td>
                                    <td>
                                        {{ $txn->description }}
                                        <span class="block text-xs text-[var(--text-muted)]">
                                            {{ collect([$txn->category ? ($categories[$txn->category] ?? $txn->category) : null, $txn->payee_name, $txn->receipt_reference ? 'Receipt '.$txn->receipt_reference : null, $txn->project?->name, $txn->creator?->name])->filter()->implode(' · ') }}
                                        </span>
                                        @if ($txn->reversalOf)<span class="block text-xs text-[var(--text-muted)]">Reverses {{ $txn->reversalOf->number }}</span>@endif
                                        @if ($txn->reversedBy)<span class="block text-xs text-[var(--text-muted)]">Reversed by {{ $txn->reversedBy->number }}</span>@endif
                                        @if ($txn->projectCost)<a href="{{ route('project-costs.show', $txn->projectCost) }}" class="block text-xs text-brand-600">Cost {{ $txn->projectCost->number }}</a>@endif
                                    </td>
                                    <td class="whitespace-nowrap text-right font-mono {{ (float) $txn->amount < 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ number_format((float) $txn->amount, 2) }}</td>
                                    <td class="text-right font-mono">{{ number_format((float) $txn->balance_after, 2) }}</td>
                                    @if ($canReverse)
                                        <td>
                                            @if ($txn->type !== 'reversal' && ! $txn->reversedBy)
                                                <details>
                                                    <summary class="cursor-pointer text-xs text-brand-600">Reverse</summary>
                                                    <form method="POST" action="{{ route('petty-cash.reverse', $txn) }}" class="mt-2 w-56 space-y-2">@csrf
                                                        <x-input name="reason" placeholder="Why?"/>
                                                        <button class="btn btn-danger btn-sm w-full">Post reversal</button>
                                                    </form>
                                                </details>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-8 text-center text-sm text-[var(--text-muted)]">No cash has moved through this tin yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($transactions->hasPages())<div class="p-4">{{ $transactions->links() }}</div>@endif
            </div>
            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this account."/>
        </div>
        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Custodian" :value="$account->custodian?->name"/>
                    <x-register.fact label="Unit" :value="$account->orgUnit?->name"/>
                    <x-register.fact label="Project" :value="$account->project?->name"/>
                    <x-register.fact label="Status" :value="$account->is_active ? 'Active' : 'Closed'"/>
                    <x-register.fact label="Opened by" :value="$account->creator?->name"/>
                </dl>
                @if ($account->notes)<p class="mt-4 whitespace-pre-line text-sm text-[var(--text-muted)]">{{ $account->notes }}</p>@endif
            </div>
            @if ($canReplenish)
                <form method="POST" action="{{ route('petty-cash.replenish', $account) }}" class="card space-y-3 p-5">@csrf
                    <h2 class="card-title">Replenish</h2>
                    <x-field label="Amount" name="amount" required><x-input type="number" step="0.01" min="0.01" name="amount"/></x-field>
                    <x-field label="Date" name="transacted_on" required><x-input type="date" name="transacted_on" :value="now()->toDateString()"/></x-field>
                    <x-field label="Description" name="description" required><x-input name="description" placeholder="Cash drawn from bank"/></x-field>
                    <x-field label="Reference" name="receipt_reference"><x-input name="receipt_reference"/></x-field>
                    <button class="btn btn-primary btn-sm w-full">Put cash in</button>
                </form>
            @endif
            @if ($canDisburse)
                <form method="POST" action="{{ route('petty-cash.disburse', $account) }}" class="card space-y-3 p-5">@csrf
                    <h2 class="card-title">Pay out</h2>
                    <x-field label="Amount" name="amount" required><x-input type="number" step="0.01" min="0.01" name="amount"/></x-field>
                    <x-field label="Date" name="transacted_on" required><x-input type="date" name="transacted_on" :value="now()->toDateString()"/></x-field>
                    <x-field label="Category" name="category" required><x-select name="category" :options="$categories" placeholder="Choose a category"/></x-field>
                    <x-field label="Description" name="description" required><x-input name="description" placeholder="What the cash was spent on"/></x-field>
                    <x-field label="Paid to" name="payee_name"><x-input name="payee_name"/></x-field>
                    <x-field label="Receipt" name="receipt_reference"><x-input name="receipt_reference"/></x-field>
                    <x-field label="Project" name="project_id" help="Charged to the project as a cost."><x-select name="project_id" :options="$projects" :selected="$account->project_id" placeholder="No project"/></x-field>
                    <button class="btn btn-primary btn-sm w-full">Pay out</button>
                </form>
            @endif
            @if ($canDelete)
                <div class="card p-5"><x-delete-form :action="route('petty-cash.destroy', $account)" label="Delete account" confirm="Delete this unused petty cash account?"/></div>
            @endif
        </div>
    </div>
</x-layouts.app>
