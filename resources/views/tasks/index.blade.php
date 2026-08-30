{{--
    The task register.

    This is the first page in the system where the two visibility axes are visible at once. A row
    reaches this list because it is assigned to you, OR because it sits in a unit you reach on the
    org ladder, OR because it belongs to a project you are a member of. None of those three is a
    branch in the controller — they are one ScopeCompiler call (§90).

    "No tasks you can see" therefore means exactly that, and never "no tasks exist". The empty state
    says so out loud, because a person who reads absence as non-existence makes worse decisions than
    one who knows to ask (§61).

    Expects: $tasks, $statuses, $projects, $units, $priorities, $views, $filters, $canCreate
--}}
<x-layouts.app title="Tasks">

    <x-page-header
        title="Tasks"
        description="Work assigned to you, work in the units you oversee, and work on the projects you are on.">
        @if ($canCreate)
            <x-slot:actions>
                <a href="{{ route('tasks.create') }}" class="btn btn-primary btn-sm">
                    <x-icon name="plus" class="h-3.5 w-3.5"/> New task
                </a>
            </x-slot:actions>
        @endif
    </x-page-header>

    {{-- ---------------------------------------------------------------- filters --}}
    <form method="GET" action="{{ route('tasks.index') }}" class="card mb-5 p-4">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <div class="lg:col-span-2">
                <label for="q" class="label">Search</label>
                <x-input name="q" :value="$filters['q']" placeholder="Title or task number"/>
            </div>

            <div>
                <label for="view" class="label">Show</label>
                <x-select name="view" :options="$views" :selected="$filters['view']"/>
            </div>

            <div>
                <label for="status" class="label">Status</label>
                <x-select name="status" :options="$statuses" :selected="$filters['status']" placeholder="Any status"/>
            </div>

            <div>
                <label for="priority" class="label">Priority</label>
                <x-select name="priority" :options="$priorities" :selected="$filters['priority']" placeholder="Any priority"/>
            </div>
        </div>

        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label for="project" class="label">Project</label>
                <x-select name="project" :options="$projects" :selected="$filters['project']" placeholder="Any project"/>
            </div>

            <div>
                <label for="unit" class="label">Responsible unit</label>
                <x-select name="unit" :options="$units" :selected="$filters['unit']" placeholder="Anywhere"/>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2">
            <button type="submit" class="btn btn-secondary">
                <x-icon name="filter" class="h-3.5 w-3.5"/> Filter
            </button>
            @if (array_filter($filters, fn ($v, $k) => $v && ! ($k === 'view' && $v === 'mine'), ARRAY_FILTER_USE_BOTH))
                <a href="{{ route('tasks.index') }}" class="btn btn-ghost">Clear</a>
            @endif
        </div>
    </form>

    {{-- ---------------------------------------------------------------- results --}}
    <div class="card overflow-hidden">
        @if ($tasks->isEmpty())
            <x-empty-state
                icon="check-square"
                title="No tasks you can see"
                description="Either nothing matches this filter, or the work it would match sits outside your access — from here those two look the same, and deliberately so. If you were expecting a job to appear, ask to be assigned it or added to its project rather than assuming it is not there.">
                @if ($canCreate)
                    <x-slot:actions>
                        <a href="{{ route('tasks.create') }}" class="btn btn-primary btn-sm">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Raise a task
                        </a>
                    </x-slot:actions>
                @endif
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Status</th>
                            <th>Assigned to</th>
                            <th>Where</th>
                            <th>Due</th>
                            <th>Checklist</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tasks as $task)
                            <tr>
                                <td>
                                    <a href="{{ route('tasks.show', $task) }}"
                                       class="font-medium text-[var(--text-strong)] hover:text-brand-600">{{ $task->title }}</a>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 font-mono text-xs text-[var(--text-muted)]">
                                        {{ $task->number }}
                                        @if ($task->parent_id)
                                            <span class="font-sans">· subtask</span>
                                        @endif
                                    </span>
                                    @if ($task->priority !== 'normal')
                                        <x-badge :tone="$task->priority === 'critical' ? 'danger' : ($task->priority === 'high' ? 'warning' : 'neutral')"
                                                 class="mt-1">
                                            <x-icon name="flag" class="h-3 w-3"/> {{ ucfirst((string) $task->priority) }}
                                        </x-badge>
                                    @endif
                                </td>
                                <td>
                                    @if ($task->status)
                                        <x-badge :tone="$task->status->color ?? 'neutral'">{{ $task->status->label }}</x-badge>
                                    @else
                                        <span class="text-[var(--text-muted)]">—</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    @if ($task->assignee)
                                        {{ $task->assignee->name }}
                                    @else
                                        <span class="italic">Nobody yet</span>
                                    @endif
                                </td>
                                <td class="text-[var(--text-muted)]">
                                    {{ $task->project?->name ?? 'No project' }}
                                    @if ($task->orgUnit)
                                        <span class="mt-0.5 block text-xs">{{ $task->orgUnit->name }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($task->due_on)
                                        <span class="{{ $task->isOverdue() ? 'font-medium text-rose-600' : 'text-[var(--text-muted)]' }}">
                                            {{ $task->due_on->format('j M Y') }}
                                        </span>
                                        @if ($task->isOverdue())
                                            <span class="mt-0.5 block text-xs text-rose-600">
                                                {{ abs((int) $task->daysRemaining()) }} days past
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-[var(--text-muted)]">No date set</span>
                                    @endif
                                </td>
                                <td>
                                    @if ((int) $task->checklist_items_count > 0)
                                        @php
                                            $done = (int) $task->checklist_done_count;
                                            $total = (int) $task->checklist_items_count;
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <span class="h-1.5 w-16 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                                                <span class="block h-full rounded-full bg-brand-500"
                                                      style="width: {{ (int) round($done / $total * 100) }}%"></span>
                                            </span>
                                            <span class="text-xs text-[var(--text-muted)]">{{ $done }}/{{ $total }}</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-[var(--text-muted)]">—</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('tasks.show', $task) }}" class="btn btn-sm btn-ghost">Open</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2 border-t border-[var(--surface-border)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-[var(--text-muted)]">
                    Showing {{ $tasks->firstItem() }}–{{ $tasks->lastItem() }} of {{ $tasks->total() }} you can see.
                </p>
                @if ($tasks->hasPages())
                    {{ $tasks->links() }}
                @endif
            </div>
        @endif
    </div>

</x-layouts.app>
