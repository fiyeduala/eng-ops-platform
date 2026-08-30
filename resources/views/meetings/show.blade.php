{{--
    One meeting.

    Four separate authorities are visible on this page, and none of them implies another:

      ARRANGING    `meeting.edit`            — time, place, agenda, and who is invited
      RECORDING    `meeting.record_minutes`  — writing what happened, and taking the register
      CIRCULATING  `meeting.circulate`       — releasing the record to the room
      CANCELLING   `meeting.cancel`          — calling it off, with a reason

    The MINUTES block is the one field-level rule in the application. Everybody who can see this page
    can see that the meeting happened; what they cannot see, until it is circulated, is a half-written
    record of what was said. That is deliberately not enforced in the scope compiler — doing it there
    would remove the whole appointment from people entitled to know it is happening.

    ACTIONS are re-filtered through `task.view` rather than merely loaded, so an action assigned into
    a department the reader cannot reach is counted and never named (§61).

    Expects: $meeting, $decisions, $canViewMinutes, $actions, $hiddenActionCount, $timeline,
             $transitions, $candidates, $ownParticipation, $roles, $canEdit, $canInvite,
             $canRecordMinutes, $canCirculate, $canCancel, $canDelete, $canRaiseAction
--}}
@php
    $transitionRules = $transitions->mapWithKeys(fn ($t) => [
        (string) $t->to_status_id => (bool) $t->requires_comment,
    ])->all();

    $mayMove = $canEdit || $canCirculate || $canCancel;
    $registerTaken = $meeting->participants->whereNotNull('attended')->isNotEmpty();
@endphp

<x-layouts.app :title="$meeting->displayName()">

    <x-page-header
        :title="$meeting->title"
        :description="$meeting->number.($meeting->project ? ' · '.$meeting->project->name : '')">
        <x-slot:actions>
            @if ($canRecordMinutes)
                <a href="{{ route('meetings.minutes', $meeting) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="file-text" class="h-3.5 w-3.5"/> Minutes
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('meetings.edit', $meeting) }}" class="btn btn-sm btn-secondary">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canDelete)
                <x-delete-form :action="route('meetings.destroy', $meeting)"
                               label="Delete"
                               confirm="Delete {{ $meeting->number }}? Only offered because it produced no record."/>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($meeting->cancelled_reason)
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-rose-400 p-4">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0 text-rose-600"/>
            <div class="text-sm text-[var(--text-body)]">
                <p class="font-medium">This meeting was cancelled.</p>
                <p class="mt-1 text-[var(--text-muted)]">{{ $meeting->cancelled_reason }}</p>
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ summary --}}
        <div class="space-y-6">

            {{-- ---------------------------------------------------- when --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">When</h3>
                    @if ($meeting->status)
                        <x-badge :tone="$meeting->status->color ?? 'neutral'">{{ $meeting->status->label }}</x-badge>
                    @endif
                </div>

                <div class="p-5">
                    <p class="text-sm font-medium text-[var(--text-strong)]">
                        {{ $meeting->starts_at?->format('l j F Y') }}
                    </p>
                    <p class="mt-0.5 text-sm text-[var(--text-muted)]">
                        {{ $meeting->starts_at?->format('H:i') }}
                        @if ($meeting->ends_at)
                            – {{ $meeting->ends_at->format('H:i') }}
                            <span class="text-xs">({{ $meeting->durationMinutes() }} min)</span>
                        @endif
                    </p>

                    <div class="mt-4 space-y-1 text-sm">
                        @if ($meeting->location)
                            <p class="flex items-center gap-2 text-[var(--text-body)]">
                                <x-icon name="pin" class="h-3.5 w-3.5 text-[var(--text-muted)]"/> {{ $meeting->location }}
                            </p>
                        @endif
                        @if ($meeting->meeting_url)
                            <p class="flex items-center gap-2">
                                <x-icon name="video" class="h-3.5 w-3.5 text-[var(--text-muted)]"/>
                                <a href="{{ $meeting->meeting_url }}" rel="noopener noreferrer" target="_blank"
                                   class="truncate text-brand-600 hover:underline">Join online</a>
                            </p>
                        @endif
                        @if (! $meeting->location && ! $meeting->meeting_url)
                            <p class="text-[var(--text-muted)]">No location set.</p>
                        @endif
                    </div>

                    <div class="mt-4">
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $meeting->confidentiality?->badgeClasses() }}">
                            <x-icon name="lock" class="h-3 w-3"/> {{ $meeting->confidentiality?->label() }}
                        </span>
                    </div>
                </div>

                {{-- -------------------------------------------------- transitions --}}
                @if ($mayMove)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        @if ($transitions->isEmpty())
                            <p class="text-sm text-[var(--text-muted)]">
                                There is nowhere for this meeting to move from here — either it has reached a
                                final status, or the moves that exist need a permission you do not hold.
                            </p>
                        @else
                            <form method="POST" action="{{ route('meetings.status', $meeting) }}"
                                  x-data="{ target: '', rules: @js($transitionRules) }">
                                @csrf
                                <label for="status_id" class="label">Move this meeting to</label>
                                <select name="status_id" id="status_id" x-model="target" class="select" required>
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
                                    <textarea name="comment" id="comment" rows="2" class="textarea"
                                              :required="rules[target]"
                                              placeholder="Used as the cancellation reason, and recorded on the timeline."></textarea>
                                </div>

                                @error('status_id')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror

                                <button type="submit" class="btn btn-primary btn-sm mt-3" x-bind:disabled="target === ''">
                                    <x-icon name="check" class="h-3.5 w-3.5"/> Apply
                                </button>
                            </form>

                            <p class="mt-3 text-xs text-[var(--text-muted)]">
                                Circulating the minutes releases them to everybody in the room, and is refused
                                while there are no minutes to release. Cancelling notifies them with the reason.
                            </p>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ---------------------------------------------------- your invitation --}}
            @if ($ownParticipation)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Your invitation</h3>
                        <x-badge tone="neutral">{{ ucfirst((string) $ownParticipation->role) }}</x-badge>
                    </div>

                    <div class="p-5">
                        @if ($ownParticipation->apologies)
                            <p class="text-sm text-[var(--text-body)]">You have sent your apologies:</p>
                            <p class="mt-1 border-l-2 border-[var(--surface-border)] pl-3 text-sm text-[var(--text-muted)]">
                                {{ $ownParticipation->apologies }}
                            </p>
                        @else
                            <p class="text-sm text-[var(--text-muted)]">
                                You are expected at this meeting.
                            </p>
                        @endif

                        @unless ($meeting->isPast() || $meeting->isCancelled())
                            <form method="POST" action="{{ route('meetings.participants.respond', [$meeting, $ownParticipation]) }}"
                                  class="mt-4 space-y-3">
                                @csrf
                                <div>
                                    <label for="apologies" class="label">Send apologies</label>
                                    <x-textarea name="apologies" :value="$ownParticipation->apologies" rows="2"
                                                placeholder="Briefly, so the chair knows why."/>
                                </div>
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    <x-icon name="mail" class="h-3.5 w-3.5"/> Send apologies
                                </button>
                                <p class="text-xs text-[var(--text-muted)]">
                                    This records you as not attending. Only you can send your own apologies.
                                </p>
                            </form>
                        @endunless
                    </div>
                </div>
            @endif

            {{-- ---------------------------------------------------- particulars --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-4 p-5">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Organizer</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $meeting->organizer?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Project</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($meeting->project)
                                <a href="{{ route('projects.show', $meeting->project) }}" class="text-brand-600 hover:underline">
                                    {{ $meeting->project->name }}
                                </a>
                            @else
                                Not filed against a project
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Department</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">{{ $meeting->orgUnit?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Attendance</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            {{ $meeting->attendanceSummary() ?? 'Register not taken' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">Minutes</dt>
                        <dd class="mt-1 text-sm text-[var(--text-strong)]">
                            @if ($meeting->minutesCirculated())
                                Circulated {{ $meeting->minutes_circulated_at->format('j M Y') }}
                            @elseif ($meeting->hasMinutes())
                                Drafted, not yet circulated
                            @else
                                Not recorded
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- ------------------------------------------------------------ detail --}}
        <div class="space-y-6 lg:col-span-2">

            @if ($meeting->agenda)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Agenda</h3>
                    </div>
                    <div class="p-5">
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $meeting->agenda }}</p>
                    </div>
                </div>
            @endif

            {{-- ---------------------------------------------------- minutes --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Minutes</h3>
                    @if ($meeting->minutesCirculated())
                        <x-badge tone="success"><x-icon name="check" class="h-3 w-3"/> Circulated</x-badge>
                    @elseif ($meeting->hasMinutes())
                        <x-badge tone="warning">Draft</x-badge>
                    @endif
                </div>

                @if ($canViewMinutes)
                    <div class="p-5">
                        @unless ($meeting->minutesCirculated())
                            <p class="mb-4 rounded-md bg-[var(--surface-muted)] p-3 text-xs text-[var(--text-muted)]">
                                You are seeing an uncirculated draft because you wrote it, called the meeting, or
                                hold the permission to release it. Nobody else in the room can read this yet.
                            </p>
                        @endunless
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $meeting->minutes }}</p>
                        @if ($meeting->minutesRecorder)
                            <p class="mt-4 text-xs text-[var(--text-muted)]">
                                Recorded by {{ $meeting->minutesRecorder->name }}
                                @if ($meeting->minutes_recorded_at)
                                    on {{ $meeting->minutes_recorded_at->format('j M Y') }}
                                @endif
                            </p>
                        @endif
                    </div>
                @elseif ($meeting->hasMinutes())
                    <x-empty-state
                        icon="lock"
                        title="Minutes not circulated yet"
                        description="A record of this meeting has been drafted but not released. It becomes readable here the moment whoever is responsible circulates it — you will be notified."/>
                @else
                    <x-empty-state
                        icon="file-text"
                        title="No minutes recorded"
                        description="Nothing has been written down yet. A meeting without minutes leaves no record of what was decided, which is the thing anybody looking at this page in a year will want.">
                        @if ($canRecordMinutes)
                            <x-slot:actions>
                                <a href="{{ route('meetings.minutes', $meeting) }}" class="btn btn-primary btn-sm">
                                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Write the minutes
                                </a>
                            </x-slot:actions>
                        @endif
                    </x-empty-state>
                @endif
            </div>

            {{-- ---------------------------------------------------- decisions --}}
            @if ($canViewMinutes)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Decisions</h3>
                        @if ($decisions->isNotEmpty())
                            <span class="text-xs text-[var(--text-muted)]">{{ $decisions->count() }} recorded</span>
                        @endif
                    </div>

                    @if ($decisions->isEmpty())
                        <x-empty-state
                            icon="gavel"
                            title="No decisions recorded"
                            description="A decision line is what people come back to read. It follows the same visibility as the minutes, because a decision IS minutes content."/>
                    @else
                        <ol class="divide-y divide-[var(--surface-border)]">
                            @foreach ($decisions as $decision)
                                <li class="flex gap-3 px-5 py-4">
                                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[var(--surface-muted)] text-xs font-medium text-[var(--text-muted)]">
                                        {{ $decision->sequence }}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-[var(--text-strong)]">{{ $decision->decision }}</p>
                                        @if ($decision->rationale)
                                            <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $decision->rationale }}</p>
                                        @endif
                                        @if ($decision->owner)
                                            <p class="mt-1 text-xs text-[var(--text-muted)]">Owner: {{ $decision->owner->name }}</p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            @endif

            {{-- ---------------------------------------------------- actions --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Actions arising</h3>
                    @if ($hiddenActionCount > 0)
                        <span class="text-xs text-[var(--text-muted)]">
                            {{ $actions->count() }} of {{ $actions->count() + $hiddenActionCount }} shown
                        </span>
                    @endif
                </div>

                @if ($actions->isEmpty() && $hiddenActionCount === 0)
                    <x-empty-state
                        icon="check-square"
                        title="Nothing raised yet"
                        description="Actions from a meeting are ordinary tasks. That is the point: they land on the assignee's list, in their notifications, on the calendar and in the overdue report — none of which would look inside a private minutes table."/>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($actions as $action)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('tasks.show', $action) }}"
                                       class="truncate text-sm font-medium text-[var(--text-strong)] hover:text-brand-600">
                                        {{ $action->title }}
                                    </a>
                                    <p class="truncate text-xs text-[var(--text-muted)]">
                                        <span class="font-mono">{{ $action->number }}</span>
                                        · {{ $action->assignee?->name ?? 'Unassigned' }}
                                        @if ($action->due_on)
                                            · due {{ $action->due_on->format('j M Y') }}
                                        @endif
                                    </p>
                                </div>
                                @if ($action->status)
                                    <x-badge :tone="$action->status->color ?? 'neutral'">{{ $action->status->label }}</x-badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($hiddenActionCount > 0)
                        <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                            {{ $hiddenActionCount }} further action(s) were raised here that your access does not
                            reach. They are counted rather than named.
                        </p>
                    @endif
                @endif

                @if ($canRecordMinutes && $canRaiseAction)
                    <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                        Actions are raised while writing the record, on the
                        <a href="{{ route('meetings.minutes', $meeting) }}" class="text-brand-600 hover:underline">minutes page</a>.
                        Each one inherits this meeting's project, department and sensitivity, and stays linked back here.
                    </p>
                @endif
            </div>

            {{-- ---------------------------------------------------- participants --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Who is expected</h3>
                    <span class="text-xs text-[var(--text-muted)]">{{ $meeting->participants->count() }} invited</span>
                </div>

                @if ($meeting->participants->isEmpty())
                    <x-empty-state
                        icon="users"
                        title="Nobody invited yet"
                        description="Inviting somebody gives them sight of this meeting — the same grant a task assignment is."/>
                @else
                    {{--
                        Read-only here. The register is taken on the minutes page, not on this one:
                        marking who turned up is part of writing the record, and putting the
                        checkboxes here would mean nesting the "remove" forms inside the register
                        form, which is not valid markup and is not how anybody thinks about it.
                    --}}
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($meeting->participants as $participant)
                            <li class="flex items-center justify-between gap-3 px-5 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-[var(--text-strong)]">
                                        {{ $participant->user?->name ?? 'Unknown account' }}
                                    </p>
                                    <p class="text-xs text-[var(--text-muted)]">
                                        {{ ucfirst((string) $participant->role) }} · {{ $participant->attendanceLabel() }}
                                    </p>
                                    @if ($participant->apologies)
                                        <p class="mt-1 border-l-2 border-[var(--surface-border)] pl-2 text-xs text-[var(--text-muted)]">
                                            {{ $participant->apologies }}
                                        </p>
                                    @endif
                                </div>

                                @if ($canInvite)
                                    <x-delete-form :action="route('meetings.participants.destroy', [$meeting, $participant])"
                                                   label="Remove"
                                                   confirm="Remove from this meeting?"/>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($canRecordMinutes)
                        <p class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                            {{ $registerTaken ? 'The register has been taken.' : 'The register has not been taken.' }}
                            <a href="{{ route('meetings.minutes', $meeting) }}" class="text-brand-600 hover:underline">
                                {{ $registerTaken ? 'Amend it' : 'Take it' }} on the minutes page.
                            </a>
                        </p>
                    @endif
                @endif

                @if ($canInvite)
                    @if ($candidates === [])
                        <p class="border-t border-[var(--surface-border)] px-5 py-4 text-sm text-[var(--text-muted)]">
                            There is nobody left you can invite. Inviting somebody gives them sight of this
                            meeting, so the list is limited to accounts your own access reaches.
                        </p>
                    @else
                        <form method="POST" action="{{ route('meetings.participants.store', $meeting) }}"
                              class="space-y-3 border-t border-[var(--surface-border)] p-4">
                            @csrf

                            <div>
                                <label for="participants" class="label">Invite</label>
                                <select name="participants[]" id="participants" multiple size="6" class="select h-auto">
                                    @foreach ($candidates as $id => $name)
                                        <option value="{{ $id }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                @error('participants')
                                    <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <div class="sm:w-56">
                                    <label for="role" class="label">As</label>
                                    <x-select name="role" :options="$roles" selected="participant"/>
                                </div>
                                <button type="submit" class="btn btn-secondary">
                                    <x-icon name="plus" class="h-3.5 w-3.5"/> Invite
                                </button>
                            </div>
                        </form>
                    @endif
                @endif
            </div>

            {{-- ---------------------------------------------------- timeline --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Activity</h3>
                </div>

                @if ($timeline->isEmpty())
                    <x-empty-state
                        icon="clock"
                        title="Nothing recorded yet"
                        description="Invitations, reschedules, the register, decisions and circulation appear here in order."/>
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

</x-layouts.app>
