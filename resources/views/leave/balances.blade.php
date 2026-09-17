<x-layouts.app title="Leave balances">
    <x-page-header :title="'Leave balances '.$year" description="What each person was granted. Days used are counted from their leave, and are never typed in."/>
    @include('finance.errors')
    @php
        $trim = fn ($n) => rtrim(rtrim((string) $n, '0'), '.') ?: '0';
    @endphp
    <form method="GET" class="card mb-5 flex flex-wrap gap-3 p-4">
        <x-select name="type" :options="$types" :selected="$type?->id"/>
        <x-input type="number" name="year" :value="$year" min="2000" max="2100" class="w-28"/>
        <button class="btn btn-secondary">Show</button>
    </form>
    @if ($type === null)
        <div class="card p-10 text-center text-sm">No leave type is counted against a balance. Set "Days a year" on a leave type first.</div>
    @else
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Person</th><th class="text-right">Entitled</th><th class="text-right">Carried over</th><th class="text-right">Adjustment</th><th class="text-right">Used</th><th class="text-right">Left</th><th></th></tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php($balance = $row['balance'])
                        <tr x-data="{ open: false }">
                            <td>{{ $row['employee']->fullName() }}</td>
                            <td class="text-right font-mono">{{ $trim($balance->entitled) }}</td>
                            <td class="text-right font-mono">{{ $trim($balance->carried_over) }}</td>
                            <td class="text-right font-mono">{{ $trim($balance->adjustment) }}</td>
                            <td class="text-right font-mono">{{ $trim($row['used']) }}</td>
                            <td class="text-right font-mono font-semibold">{{ $trim($row['left']) }}</td>
                            <td class="text-right">
                                @can('update', $balance)
                                    <button type="button" class="btn btn-ghost btn-sm" @click="open = ! open">Correct</button>
                                    <form x-show="open" x-cloak method="POST" action="{{ route('leave-balances.update', $balance) }}" class="mt-2 grid gap-2 text-left sm:grid-cols-4">
                                        @csrf @method('PUT')
                                        <x-input type="number" step="0.5" min="0" max="366" name="entitled" :value="$balance->entitled" aria-label="Entitled"/>
                                        <x-input type="number" step="0.5" min="0" max="366" name="carried_over" :value="$balance->carried_over" aria-label="Carried over"/>
                                        <x-input type="number" step="0.5" min="-366" max="366" name="adjustment" :value="$balance->adjustment" aria-label="Adjustment"/>
                                        <x-input name="notes" :value="$balance->notes" placeholder="Why" aria-label="Notes"/>
                                        <button class="btn btn-primary btn-sm sm:col-span-4">Save</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center">Nobody you can see has a personnel record.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-layouts.app>
