{{--
    Corrective actions — the register that decides whether any of the rest of this mattered.

    IT OPENS ON "MINE", not on everything. Every other register in this application opens on the
    organization's outstanding work because that is what a manager wants; this one opens on the
    viewer's own list because a corrective action is a personal obligation with a date on it, and
    the question the person opening this page is actually asking is "what do I owe".

    DUE DATE IS THE SORT AND OVERDUE IS THE COLOUR. Nothing else about an action matters as much as
    whether it is late.

    THE REGISTER NEVER NAMES THE SOURCE. An action raised from a confidential incident is routinely
    assigned to somebody who cannot open that incident — a supervisor told to fit a guard rail has no
    business with the injury detail — and even the incident number is an association worth withholding
    from a list anybody with `corrective_action.view` can page through. The action's own title has to
    carry the work instruction, and the link back is offered on its page only to those who can follow
    it (§68).

    Expects: $actions, $statuses, $projects, $types, $priorities, $views, $filters, $canCreate
--}}
<x-layouts.app title="Corrective actions">

    <x-page-header
        title="Corrective actions"
        description="What was promised, by whom, by when, and whether it worked.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('corrective-actions.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> Raise an action
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    <x-register.views route="corrective-actions.index" :views="$views" :current="$filters['view']"/>

    <x-register.filters route="corrective-actions.index" :filters="$filters" :projects="$projects"
                        :statuses="$statuses" placeholder="Number or title">
        <x-slot:extra>
            <div>
                <label for="action_type" class="label">Type</label>
                <x-select name="action_type" :options="$types" :selected="$filters['action_type']"
                          placeholder="Any type"/>
            </div>
            <div>
                <label for="priority" class="label">Priority</label>
                <x-select name="priority" :options="$priorities" :selected="$filters['priority']"
                          placeholder="Any priority"/>
            </div>
        </x-slot:extra>
    </x-register.filters>

    <x-register.table :records="$actions" icon="target"
                      title="No corrective actions"
                      description="Either nothing matches these filters, or the actions that do sit on work your access does not reach.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('corrective-actions.create') }}" class="btn btn-primary btn-sm">Raise an action</a>
            </x-slot:actions>
        @endif

        <x-slot:head>
            <tr>
                <th>Number</th>
                <th>What needs doing</th>
                <th>Owner</th>
                <th>Priority</th>
                <th>Due</th>
                <th>Effectiveness</th>
                <th>Status</th>
            </tr>
        </x-slot:head>

        <x-slot:rows>
            @foreach ($actions as $action)
                <tr>
                    <td class="whitespace-nowrap">
                        <a href="{{ route('corrective-actions.show', $action) }}"
                           class="font-mono text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                            {{ $action->number }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $action->action_type ?: 'Action' }}
                        </span>
                    </td>

                    <td>
                        <a href="{{ route('corrective-actions.show', $action) }}"
                           class="text-[var(--text-body)] hover:text-brand-600">
                            {{ $action->title }}
                        </a>
                        <span class="block text-xs text-[var(--text-muted)]">
                            @if ($action->project)
                                {{ $action->project->displayName() }}
                            @endif
                            @if ($action->discipline)
                                · {{ $action->discipline->name }}
                            @endif
                        </span>
                    </td>

                    <td class="whitespace-nowrap text-[var(--text-muted)]">
                        {{ $action->assignee?->name ?? 'Unassigned' }}
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($action->priority)
                            <x-badge tone="neutral">{{ $action->priority }}</x-badge>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($action->due_on)
                            @php $late = ! $action->isComplete()
                                && $action->due_on->isBefore(now()->startOfDay()); @endphp
                            <span @class([
                                'text-sm',
                                'font-medium text-rose-600' => $late,
                                'text-[var(--text-muted)]' => ! $late,
                            ])>{{ $action->due_on->format('j M Y') }}</span>
                            @if ($late)
                                <span class="block text-xs text-rose-600">overdue</span>
                            @endif
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    {{-- Verified is not the same as done, and the register refuses to blur them.
                         An action marked complete by the person asked to do it proves nothing
                         until somebody else has looked. --}}
                    <td class="whitespace-nowrap">
                        @if ($action->effectiveness)
                            <x-badge tone="success">{{ $action->effectiveness }}</x-badge>
                        @elseif ($action->isComplete())
                            <span class="text-xs text-[var(--text-muted)]">awaiting verification</span>
                        @else
                            <span class="text-[var(--text-muted)]">—</span>
                        @endif
                    </td>

                    <td class="whitespace-nowrap">
                        @if ($action->status)
                            <x-badge :tone="$action->status->tone()">{{ $action->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-slot:rows>
    </x-register.table>

</x-layouts.app>
