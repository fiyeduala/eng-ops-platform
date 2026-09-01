{{--
    The actions hanging off one record, and the form that raises another.

    ONE PARTIAL FOR EVERY SOURCE. An incident, an investigation, an observation, an inspection and an
    NCR all raise corrective actions the same way, through the same service, and if each register had
    written its own version of this card they would have drifted apart within a phase (§90). The
    caller supplies the record and the route; nothing here knows which register it is sitting in.

    THE CLASSIFICATION POSTED HERE IS IGNORED. `openFrom()` sets every action to `internal` on
    purpose: an action reading "replace the damaged sling" is not a secret even when the incident
    behind it is, and it has to be readable by the person doing the work (§68). The hidden field
    exists only because the form request insists on the key.

    Expects: $record, $route, $canRaiseAction, $actionTypes, $actionPriorities, $people
--}}
@php $actions = $record->correctiveActions; @endphp

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Corrective actions</h3>
        <span class="text-xs text-[var(--text-muted)]">{{ $actions->count() }} raised</span>
    </div>

    @if ($actions->isEmpty())
        <p class="p-5 text-sm text-[var(--text-muted)]">
            Nothing has been raised yet. Findings that never become somebody's job by a date are
            the reason the same thing happens twice.
        </p>
    @else
        <ul class="divide-y divide-[var(--surface-border)]">
            @foreach ($actions as $action)
                <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-4">
                    <div class="min-w-0">
                        <a href="{{ route('corrective-actions.show', $action) }}"
                           class="font-mono text-xs text-[var(--brand-600)] hover:underline">
                            {{ $action->number }}
                        </a>
                        <p class="mt-0.5 text-sm font-medium text-[var(--text-strong)]">{{ $action->title }}</p>
                        <p class="mt-0.5 text-xs text-[var(--text-muted)]">
                            {{ $action->assignee?->name ?? 'Unassigned' }}
                            @if ($action->due_on)
                                · due {{ $action->due_on->format('j M Y') }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($action->priority)
                            <x-badge tone="neutral">{{ $action->priority }}</x-badge>
                        @endif
                        @if ($action->status)
                            <x-badge :tone="$action->status->tone()">{{ $action->status->label }}</x-badge>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canRaiseAction)
        <form method="POST" action="{{ $route }}"
              class="space-y-5 border-t border-[var(--surface-border)] p-5">
            @csrf

            <x-field label="What needs doing" name="title" required>
                <x-input name="title" :value="old('title')" required/>
            </x-field>

            <x-field label="What good looks like" name="description" required
                     help="Written so that somebody else can tell whether it has been done.">
                <x-textarea name="description" rows="3" required>{{ old('description') }}</x-textarea>
            </x-field>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-field label="Type" name="action_type">
                    <x-select name="action_type" :options="$actionTypes" :selected="old('action_type')"
                              placeholder="Choose one"/>
                </x-field>

                <x-field label="Priority" name="priority">
                    <x-select name="priority" :options="$actionPriorities" :selected="old('priority')"
                              placeholder="Choose one"/>
                </x-field>

                <x-field label="Due by" name="due_on">
                    <x-input type="date" name="due_on" :value="old('due_on')"/>
                </x-field>
            </div>

            <x-field label="Assigned to" name="assigned_to"
                     help="They are notified straight away. An action nobody owns is a wish.">
                <x-select name="assigned_to" :options="$people" :selected="old('assigned_to')"
                          placeholder="Assign later"/>
            </x-field>

            <input type="hidden" name="confidentiality" value="internal">

            <button type="submit" class="btn btn-primary">Raise the action</button>
        </form>
    @endif
</div>
