{{--
    One task.

    Three lists on this page are re-filtered rather than merely loaded, and that is the point:

      - DEPENDENCIES go back through ScopeCompiler, so a blocker on a confidential project appears
        as a count you cannot open rather than as a number and a title you have no right to (§61).
      - SUBTASKS likewise.
      - The ASSIGNEE picker is built by asking TaskPolicy about each candidate, so it never becomes
        a staff directory for someone whose access says otherwise.

    Every control drawn here is drawn from a policy answer, and every one of them is asked again
    server-side when pressed. Hiding a button is a courtesy; the refusal is in the controller (§84).

    Expects: $task, $dependencies, $hiddenDependencyCount, $children, $timeline, $transitions,
             $blocking, $isFollowing, $candidates, $blockers,
             $canEdit, $canManage, $canAssign, $canDelete
--}}
@php
    $transitionRules = $transitions->mapWithKeys(fn ($t) => [
        (string) $t->to_status_id => (bool) $t->requires_comment,
    ])->all();

    $priorityTone = match ($task->priority) {
        'critical' => 'danger',
        'high' => 'warning',
        default => 'neutral',
    };
@endphp

<x-layouts.app :title="$task->displayName()">

    <x-page-header
        :title="$task->title"
        :description="$task->number.($task->project ? ' · '.$task->project->name : '')">
        <x-slot:actions>
            <form method="POST"
                  action="{{ $isFollowing ? route('tasks.unfollow', $task) : route('tasks.follow', $task) }}">
                @csrf
                @if ($isFollowing)
                    @method('DELETE')
                @endif
                <button type="submit" class="btn btn-sm btn-ghost">
                    <x-icon name="mail" class="h-3.5 w-3.5"/>
                    {{ $isFollowing ? 'Stop following' : 'Follow' }}
                </button>
            </form>

            @if ($canEdit)
                <a href="{{ route('tasks.edit', $task) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canDelete)
                <x-delete-form :action="route('tasks.destroy', $task)"
                               label="Delete"
                               confirm="Delete {{ $task->number }}? This is only offered because nothing depends on it and it has no subtasks."/>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($task->parent)
        <p class="mb-5 flex items-center gap-2 text-sm text-[var(--text-muted)]">
            <x-icon name="chevron-left" class="h-3.5 w-3.5"/>
            Subtask of
            <a href="{{ route('tasks.show', $task->parent) }}" class="text-brand-600 hover:underline">
                {{ $task->parent->displayName() }}
            </a>
        </p>
    @endif

    @if ($blocking->isNotEmpty())
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <p class="text-sm text-[var(--text-body)]">
                This task is waiting on {{ $blocking->count() }} unfinished
                {{ \Illuminate\Support\Str::plural('task', $blocking->count()) }}. It cannot be closed
                until they are — cancelling it is still allowed, because abandoning blocked work is a
                real outcome.
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ summary --}}
        <div class="space-y-6">

            {{-- ---------------------------------------------------- status --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Status</h3>
                    @if ($task->status)
                        <x-badge :tone="$task->status->color ?? 'neutral'">{{ $task->status->label }}</x-badge>
                    @endif
                </div>

                <div class="p-5">
                    <div class="flex items-center gap-3">
                        <span class="h-2 flex-1 overflow-hidden rounded-full bg-[var(--surface-muted)]">
                            <span class="block h-full rounded-full bg-brand-500"
                                  style="width: {{ max(0, min(100, (int) $task->progress_percent)) }}%"></span>
                        </span>
                        <span class="text-sm font-medium text-[var(--text-strong)]">{{ (int) $task->progress_percent }}%</span>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <x-badge :tone="$priorityTone">
                            <x-icon name="flag" class="h-3 w-3"/> {{ ucfirst((string) $task->priority) }} priority
                        </x-badge>
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $task->confidentiality?->badgeClasses() }}">
                            <x-icon name="lock" class="h-3 w-3"/> {{ $task->confidentiality?->label() }}
                        </span>
                    </div>

                    @if ($task->isOverdue())
                        <p class="mt-4 text-sm text-rose-600">
                            {{ abs((int) $task->daysRemaining()) }} days past the due date.
                        </p>
                    @endif

                    @if ($task->completed_at)
                        <p class="mt-4 text-sm text-[var(--text-muted)]">
                            Closed {{ $task->completed_at->format('j M Y') }}
                            @if ($task->completedBy)
                                by {{ $task->completedBy->name }}
                            @endif
                        </p>
                    @endif
                </div>

                {{-- -------------------------------------------------- transitions --}}
                @if ($canEdit)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        @if ($transitions->isEmpty())
                            <p class="text-sm text-[var(--text-muted)]">
                                There is nowhere for this task to move from here — either it has reached a
                                final status, or the moves that exist need a permission you do not hold.
                            </p>
                        @else
                            <form method="POST" action="{{ route('tasks.status', $task) }}"
                                  x-data="{ target: '', rules: @js($transitionRules) }">
                                @csrf
                                <label for="status_id" class="label">Move this task to</label>
                                <select name="status_id" id="status_id" x-model="target" class="input" required>
                                    <option value="">Choose a status…</option>
                                    @foreach ($transitions as $transition)
                                        <option value="{{ $transition->to_status_id }}">{{ $transition->to?->label }}</option>
                                    @endforeach
                                </select>

                                <div class="mt-3" x-show="target !== ''" x-cloak>
                                    <label for="comment" class="label">
                                        Reason
                                        <span x-show="! rules[target]" class="font-normal text-[var(--text-muted)]">(optional)</span>
                                    </label>
                                    <textarea name="comment" id="comment" rows="2" class="input"
                                              :required="rules[target]"
                                              placeholder="Recorded on the timeline for whoever reads this later."></textarea>
                                </div>

                                @error('status_id')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror

                                <button type="submit" class="btn btn-primary btn-sm mt-3" x-bind:disabled="target === ''">
                                    <x-icon name="check" class="h-3.5 w-3.5"/> Apply
                                </button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ---------------------------------------------------- assignment --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Assigned to</h3>
                </div>

                <div class="p-5">
                    <p class="text-sm font-medium text-[var(--text-strong)]">
                        {{ $task->assignee?->name ?? 'Nobody yet' }}
                    </p>
                    @if ($task->assignee && $task->assigned_at)
                        <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                            {{ $task->assignedBy ? $task->assignedBy->name.' · ' : '' }}{{ $task->assigned_at->diffForHumans() }}
                        </p>
                    @endif

                    @if ($canAssign)
                        @if ($candidates === [])
                            <p class="mt-4 text-sm text-[var(--text-muted)]">
                                There is nobody you can put on this task. Assigning somebody gives them sight
                                of it, so the list is limited to people your own access reaches.
                            </p>
                        @else
                            <form method="POST" action="{{ route('tasks.assign', $task) }}" class="mt-4">
                                @csrf
                                <label for="assignee_id" class="label">Hand it to</label>
                                <x-select name="assignee_id" :options="$candidates"
                                          :selected="$task->assignee_id" placeholder="Nobody — leave unassigned"/>
                                @error('assignee_id')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror
                                <button type="submit" class="btn btn-secondary btn-sm mt-3">
                                    <x-icon name="user" class="h-3.5 w-3.5"/> Save assignment
                                </button>
                            </form>

                            <p class="mt-3 text-xs text-[var(--text-muted)]">
                                Assigning is a grant, not a note: the person named here can open this task
                                whether or not they could otherwise reach it.
                            </p>
                        @endif
                    @endif
                </div>
            </div>

            {{-- ---------------------------------------------------- particulars --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Project</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($task->project)
                                <a href="{{ route('projects.show', $task->project) }}" class="text-brand-600 hover:underline">
                                    {{ $task->project->name }}
                                </a>
                            @else
                                Not filed against a project
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Responsible unit</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $task->orgUnit?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Dates</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $task->start_on?->format('j M Y') ?? 'No start date' }}
                            →
                            <span class="{{ $task->isOverdue() ? 'font-medium text-rose-600' : '' }}">
                                {{ $task->due_on?->format('j M Y') ?? 'no due date' }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Effort</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $task->actual_hours !== null ? rtrim(rtrim((string) $task->actual_hours, '0'), '.') : '0' }}
                            of
                            {{ $task->estimated_hours !== null ? rtrim(rtrim((string) $task->estimated_hours, '0'), '.').' hours' : '— hours' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Raised by</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $task->creator?->name ?? 'System' }}
                            <span class="block text-xs text-[var(--text-muted)]">{{ $task->created_at?->format('j M Y') }}</span>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- ------------------------------------------------------------ detail --}}
        <div class="space-y-6 lg:col-span-2">

            @if ($task->description)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">What needs doing</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $task->description }}</p>
                    </div>
                </div>
            @endif

            {{-- ---------------------------------------------------- checklist --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Checklist</h3>
                    @if ($task->checklistItems->isNotEmpty())
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ $task->checklistItems->where('is_done', true)->count() }} of
                            {{ $task->checklistItems->count() }} done
                        </span>
                    @endif
                </div>

                @if ($task->checklistItems->isEmpty())
                    <x-empty-state
                        icon="check-square"
                        title="No checklist"
                        description="Break the task into the steps somebody has to actually tick off. Each tick is stamped with who did it and when, which is what makes a checklist usable as evidence later."/>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($task->checklistItems as $item)
                            <li class="flex items-start gap-3 px-5 py-3">
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('tasks.checklist.toggle', [$task, $item]) }}"
                                          class="mt-0.5 shrink-0">
                                        @csrf
                                        <input type="hidden" name="is_done" value="{{ $item->is_done ? 0 : 1 }}">
                                        <button type="submit"
                                                class="flex h-4 w-4 items-center justify-center rounded border border-[var(--surface-border)] {{ $item->is_done ? 'bg-brand-500 text-white' : 'bg-transparent' }}"
                                                aria-label="{{ $item->is_done ? 'Untick' : 'Tick' }} {{ $item->title }}">
                                            @if ($item->is_done)
                                                <x-icon name="check" class="h-3 w-3"/>
                                            @endif
                                        </button>
                                    </form>
                                @else
                                    <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded border border-[var(--surface-border)] {{ $item->is_done ? 'bg-brand-500 text-white' : '' }}">
                                        @if ($item->is_done)
                                            <x-icon name="check" class="h-3 w-3"/>
                                        @endif
                                    </span>
                                @endif

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm {{ $item->is_done ? 'text-[var(--text-muted)] line-through' : 'text-[var(--text-body)]' }}">
                                        {{ $item->title }}
                                    </p>
                                    @if ($item->is_done && $item->done_at)
                                        <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                            {{ $item->doneBy?->name ?? 'Someone' }} · {{ $item->done_at->diffForHumans() }}
                                        </p>
                                    @endif
                                </div>

                                @if ($canEdit)
                                    <x-delete-form :action="route('tasks.checklist.destroy', [$task, $item])"
                                                   label=""
                                                   confirm="Remove?"/>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canEdit)
                    <form method="POST" action="{{ route('tasks.checklist.store', $task) }}"
                          class="flex flex-col gap-2 border-t border-[var(--surface-border)] p-4 sm:flex-row">
                        @csrf
                        <div class="flex-1">
                            <label for="title" class="sr-only">New checklist item</label>
                            <x-input name="title" placeholder="Add a step…"/>
                        </div>
                        <button type="submit" class="btn btn-secondary shrink-0">
                            <x-icon name="plus" class="h-3.5 w-3.5"/> Add
                        </button>
                    </form>
                @endif
            </div>

            {{-- ---------------------------------------------------- dependencies --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Waiting on</h3>
                    @if ($hiddenDependencyCount > 0)
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ $dependencies->count() }} of {{ $dependencies->count() + $hiddenDependencyCount }} shown
                        </span>
                    @endif
                </div>

                @if ($dependencies->isEmpty() && $hiddenDependencyCount === 0)
                    <x-empty-state
                        icon="link"
                        title="Nothing is blocking this"
                        description="A dependency says “this cannot finish until that one has”. The system enforces it: a blocked task refuses to close, though it can still be cancelled."/>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($dependencies as $edge)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('tasks.show', $edge->dependsOn) }}"
                                       class="truncate text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $edge->dependsOn?->title }}
                                    </a>
                                    <p class="truncate font-mono text-xs text-[var(--text-muted)]">
                                        {{ $edge->dependsOn?->number }}
                                        <span class="font-sans">· {{ str_replace('_', '-to-', (string) $edge->type) }}</span>
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    @if ($edge->dependsOn?->status)
                                        <x-badge :tone="$edge->dependsOn->status->color ?? 'neutral'">
                                            {{ $edge->dependsOn->status->label }}
                                        </x-badge>
                                    @endif
                                    @if ($canEdit)
                                        <x-delete-form :action="route('tasks.dependencies.destroy', [$task, $edge])"
                                                       label=""
                                                       confirm="Remove?"/>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if ($hiddenDependencyCount > 0)
                        <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                            {{ $hiddenDependencyCount }} further blocker(s) exist that your access does not reach.
                            They still hold this task closed — they are counted, not hidden.
                        </p>
                    @endif
                @endif

                @if ($canEdit && $blockers !== [])
                    <form method="POST" action="{{ route('tasks.dependencies.store', $task) }}"
                          class="flex flex-col gap-2 border-t border-[var(--surface-border)] p-4 sm:flex-row">
                        @csrf
                        <div class="flex-1">
                            <label for="depends_on_task_id" class="sr-only">Blocking task</label>
                            <x-select name="depends_on_task_id" :options="$blockers" placeholder="This task waits for…"/>
                            @error('depends_on_task_id')
                                <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-secondary shrink-0">
                            <x-icon name="link" class="h-3.5 w-3.5"/> Add
                        </button>
                    </form>
                @endif
            </div>

            {{-- ---------------------------------------------------- subtasks --}}
            @if ($children->isNotEmpty())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Subtasks</h3>
                        <span class="text-xs text-[var(--text-muted)]">{{ $children->count() }} you can see</span>
                    </div>

                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($children as $child)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('tasks.show', $child) }}"
                                       class="truncate text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $child->title }}
                                    </a>
                                    <p class="truncate text-xs text-[var(--text-muted)]">
                                        <span class="font-mono">{{ $child->number }}</span>
                                        · {{ $child->assignee?->name ?? 'Unassigned' }}
                                    </p>
                                </div>
                                @if ($child->status)
                                    <x-badge :tone="$child->status->color ?? 'neutral'">{{ $child->status->label }}</x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ---------------------------------------------------- timeline --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Activity</h3>
                    <span class="text-xs text-[var(--text-muted)]">Also filed on the project timeline</span>
                </div>

                @if ($timeline->isEmpty())
                    <x-empty-state
                        icon="clock"
                        title="Nothing recorded yet"
                        description="Assignments, status moves and checklist ticks appear here in order, and on the parent project's timeline at the same time."/>
                @else
                    <ul class="p-5">
                        @foreach ($timeline as $entry)
                            <li class="relative flex gap-3 pb-5 last:pb-0">
                                @unless ($loop->last)
                                    <span class="absolute left-[5px] top-3 h-full w-px bg-[var(--surface-border)]" aria-hidden="true"></span>
                                @endunless
                                <span class="relative mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $entry->dotClasses() }}"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm text-[var(--text-body)]">{{ $entry->description ?? $entry->verb }}</p>
                                    @if (! empty($entry->properties['comment']))
                                        <p class="mt-1 border-l-2 border-[var(--surface-border)] pl-3 text-sm text-[var(--text-muted)]">
                                            {{ $entry->properties['comment'] }}
                                        </p>
                                    @endif
                                    <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                                        {{ $entry->actorName() }} ·
                                        <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->diffForHumans() }}</time>
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$task" class="card mt-5"/>

</x-layouts.app>
