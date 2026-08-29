{{--
    The audit trail.

    Read-only, and permanently so: there is no store, update or destroy route, the policy refuses
    every write ability, and the model itself throws on update and delete. An audit trail the people
    it audits can edit is not an audit trail (§59).

    Actor names come from the rows themselves rather than from a user lookup, so an account closed
    last year is still identifiable in the history it left behind.
--}}
<x-layouts.app title="Audit trail">

    <x-page-header
        title="Audit trail"
        description="Who did what, and what changed. Entries cannot be edited or deleted by anyone, including whoever administers this system."/>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('audit.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Description, person or action"/>
            </div>

            <div>
                <label for="module" class="label">Area</label>
                <x-select name="module" :options="$modules" :selected="$filters['module']" placeholder="Everywhere"/>
            </div>

            <div>
                <label for="user_id" class="label">Done by</label>
                <x-select name="user_id" :options="$actors" :selected="$filters['user_id']" placeholder="Anyone"/>
            </div>

            <div>
                <label for="from" class="label">From</label>
                <x-input name="from" type="date" :value="$filters['from']"/>
            </div>

            <div>
                <label for="to" class="label">To</label>
                <x-input name="to" type="date" :value="$filters['to']"/>
            </div>

            <label class="flex items-center gap-2 pb-2.5 text-sm text-[var(--text-body)] sm:self-end">
                <input type="checkbox" name="privileged" value="1" @checked($filters['privileged'])
                       class="h-4 w-4 rounded border-[var(--surface-border)]">
                Only privileged actions
            </label>

            <div class="flex items-end gap-2">
                <button type="submit" class="btn btn-secondary">
                    <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
                </button>
                @if (array_filter($filters))
                    <a href="{{ route('audit.index') }}" class="btn btn-ghost">Clear</a>
                @endif
            </div>
        </div>
    </form>

    {{-- ---------------------------------------------------------------- entries --}}
    <div class="card overflow-hidden">
        @if ($entries->isEmpty())
            <x-empty-state
                icon="scroll"
                title="Nothing matches"
                description="No entries fall inside these filters. The trail records writes, not reads, so a quiet period genuinely looks like this."/>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>What happened</th>
                            <th>Area</th>
                            <th>By</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $entry)
                            <tr>
                                <td class="whitespace-nowrap align-top text-[var(--text-muted)]">
                                    <span class="block">{{ $entry->created_at->format('j M Y') }}</span>
                                    <span class="block text-xs">{{ $entry->created_at->format('H:i') }}</span>
                                </td>

                                <td class="align-top">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-[var(--text-strong)]">
                                            {{ $entry->description ?: Str::of($entry->action)->replace('_', ' ')->ucfirst() }}
                                        </span>
                                        @if ($entry->is_privileged)
                                            <x-badge tone="warning">Privileged</x-badge>
                                        @endif
                                    </div>
                                    <span class="mt-0.5 block font-mono text-xs text-[var(--text-muted)]">{{ $entry->action }}</span>
                                </td>

                                <td class="align-top text-[var(--text-muted)]">
                                    {{ $modules[$entry->module] ?? ($entry->module ?: '—') }}
                                </td>

                                <td class="align-top">
                                    <span class="block text-[var(--text-strong)]">{{ $entry->actorName() }}</span>
                                    @if ($entry->ip_address)
                                        <span class="block font-mono text-xs text-[var(--text-muted)]">{{ $entry->ip_address }}</span>
                                    @endif
                                </td>

                                <td class="align-top text-right">
                                    <a href="{{ route('audit.show', $entry) }}" class="btn btn-sm btn-ghost">Detail</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($entries->hasPages())
                <div class="border-t border-[var(--surface-border)] px-4 py-3">
                    {{ $entries->links() }}
                </div>
            @endif
        @endif
    </div>

</x-layouts.app>
