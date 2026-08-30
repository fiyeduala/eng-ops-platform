{{--
    Writing the record of a meeting.

    A separate page behind a separate permission. `meeting.record_minutes` is not `meeting.edit`: in
    most organizations a project administrator books the room, a secretary writes the minutes and the
    chair releases them, and collapsing the first two would let anybody who can move a meeting rewrite
    the account of what was decided at it.

    Nothing on this page notifies anybody. Saving minutes, taking the register and recording a
    decision are all private acts of drafting — the room is told once, when the minutes are
    CIRCULATED from the meeting page, and that is a status move with its own permission.

    Four independent forms rather than one, because each posts to its own endpoint and each is
    authorized separately on arrival (§84). They are siblings, never nested.

    Expects: $meeting, $people
--}}
@php
    $registerTaken = $meeting->participants->whereNotNull('attended')->isNotEmpty();
@endphp

<x-layouts.app :title="'Minutes · '.$meeting->number">

    <x-page-header
        :title="'Minutes: '.$meeting->title"
        :description="$meeting->number.' · '.$meeting->starts_at?->format('l j F Y, H:i')">
        <x-slot:actions>
            <a href="{{ route('meetings.show', $meeting) }}" class="btn btn-sm btn-secondary">
                <x-icon name="chevron-left" class="h-3.5 w-3.5"/> Back to the meeting
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 flex items-start gap-3 rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] p-4">
        <x-icon :name="$meeting->minutesCirculated() ? 'check' : 'lock'" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
        <p class="text-sm text-[var(--text-body)]">
            @if ($meeting->minutesCirculated())
                These minutes were circulated on
                {{ $meeting->minutes_circulated_at->format('j M Y') }} and everybody invited can now read
                them. Further edits change a record people have already been sent — the change is
                logged against the meeting either way.
            @else
                <span class="font-medium">Not circulated.</span>
                Only you, whoever called the meeting and anybody who may circulate can read what is on
                this page. The room sees nothing until the minutes are circulated from the meeting page.
            @endif
        </p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ------------------------------------------------------------ the record --}}
        <div class="space-y-6 lg:col-span-2">

            {{-- ---------------------------------------------------- minutes --}}
            <form method="POST" action="{{ route('meetings.minutes.store', $meeting) }}" class="card">
                @csrf
                @method('PUT')

                <div class="card-header">
                    <h3 class="card-title">What happened</h3>
                    @if ($meeting->minutes_recorded_at)
                        <span class="text-xs text-[var(--text-muted)]">
                            Last saved {{ $meeting->minutes_recorded_at->diffForHumans() }}
                        </span>
                    @endif
                </div>

                <div class="p-5">
                    <x-field label="Minutes" name="minutes"
                             help="Written for somebody reading it in two years who was not in the room.">
                        <x-textarea name="minutes" :value="$meeting->minutes" rows="18"
                                    placeholder="Attendance, matters arising, discussion, and what was agreed."/>
                    </x-field>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-[var(--surface-border)] px-5 py-4">
                    <p class="text-xs text-[var(--text-muted)]">Saving does not notify anybody.</p>
                    <button type="submit" class="btn btn-primary">
                        <x-icon name="check" class="h-3.5 w-3.5"/> Save minutes
                    </button>
                </div>
            </form>

            {{-- ---------------------------------------------------- decisions --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Decisions</h3>
                    <span class="text-xs text-[var(--text-muted)]">{{ $meeting->decisions->count() }} recorded</span>
                </div>

                @if ($meeting->decisions->isEmpty())
                    <x-empty-state
                        icon="gavel"
                        title="No decisions recorded"
                        description="A decision recorded here is what people come back to read. It is minutes content, so it stays withheld until the minutes are circulated."/>
                @else
                    <ol class="divide-y divide-[var(--surface-border)]">
                        @foreach ($meeting->decisions as $decision)
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

                <form method="POST" action="{{ route('meetings.decisions.store', $meeting) }}"
                      class="space-y-4 border-t border-[var(--surface-border)] p-5">
                    @csrf

                    <x-field label="Decision" name="decision" required
                             help="One decision per entry. “Agreed to…”, not a summary of the discussion.">
                        <x-input name="decision" placeholder="What was agreed" required/>
                    </x-field>

                    <x-field label="Rationale" name="rationale"
                             help="Optional, and the part that is worth the most later: why this and not the alternative.">
                        <x-textarea name="rationale" rows="3"/>
                    </x-field>

                    <x-field label="Owner" name="owner_id"
                             help="Who carries it. Naming somebody here does not create work for them — raise an action for that.">
                        @if ($people === [])
                            <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                                You cannot see login accounts, so a decision cannot be attributed here.
                            </p>
                        @else
                            <x-select name="owner_id" :options="$people" placeholder="Nobody in particular"/>
                        @endif
                    </x-field>

                    <button type="submit" class="btn btn-secondary">
                        <x-icon name="plus" class="h-3.5 w-3.5"/> Record decision
                    </button>
                </form>
            </div>

            {{-- ---------------------------------------------------- actions --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Raise an action</h3>
                </div>

                <form method="POST" action="{{ route('meetings.actions.store', $meeting) }}" class="space-y-4 p-5">
                    @csrf

                    <x-field label="What has to happen" name="title" required>
                        <x-input name="title" placeholder="e.g. Reissue drawing S-104 with the revised setting-out" required/>
                    </x-field>

                    <x-field label="Detail" name="description">
                        <x-textarea name="description" rows="3"/>
                    </x-field>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field label="Assign to" name="assignee_id"
                                 help="Only people you could assign a task to anyway.">
                            @if ($people === [])
                                <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                                    You cannot see login accounts.
                                </p>
                            @else
                                <x-select name="assignee_id" :options="$people" placeholder="Unassigned"/>
                            @endif
                        </x-field>

                        <x-field label="Due" name="due_on">
                            <x-input name="due_on" type="date"/>
                        </x-field>
                    </div>

                    <button type="submit" class="btn btn-secondary">
                        <x-icon name="check-square" class="h-3.5 w-3.5"/> Raise action
                    </button>

                    <p class="text-xs text-[var(--text-muted)]">
                        An action is an ordinary task, not a private line in the minutes: it lands on the
                        assignee's list, in their notifications, on their calendar and in the overdue report,
                        and it is notified to them straight away. It inherits this meeting's project,
                        department and sensitivity, and stays linked back to it.
                    </p>
                </form>
            </div>
        </div>

        {{-- ------------------------------------------------------------ register --}}
        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The register</h3>
                    @if ($registerTaken)
                        <x-badge tone="success">Taken</x-badge>
                    @endif
                </div>

                @if ($meeting->participants->isEmpty())
                    <x-empty-state
                        icon="users"
                        title="Nobody was invited"
                        description="Invite people from the meeting page and the register appears here."/>
                @else
                    <form method="POST" action="{{ route('meetings.attendance', $meeting) }}">
                        @csrf

                        <ul class="divide-y divide-[var(--surface-border)]">
                            @foreach ($meeting->participants as $participant)
                                <li class="px-5 py-3">
                                    <label for="attendance-{{ $participant->id }}" class="flex items-start gap-3">
                                        <input type="hidden" name="attendance[{{ $participant->id }}]" value="0">
                                        <input type="checkbox" id="attendance-{{ $participant->id }}"
                                               name="attendance[{{ $participant->id }}]" value="1"
                                               @checked($participant->attended)
                                               class="mt-0.5 h-4 w-4 shrink-0 rounded border-[var(--surface-border)]">
                                        <span class="min-w-0">
                                            <span class="block truncate text-sm font-medium text-[var(--text-strong)]">
                                                {{ $participant->user?->name ?? 'Unknown account' }}
                                            </span>
                                            <span class="block text-xs text-[var(--text-muted)]">
                                                {{ ucfirst((string) $participant->role) }} · {{ $participant->attendanceLabel() }}
                                            </span>
                                        </span>
                                    </label>

                                    @if ($participant->apologies)
                                        <p class="mt-1 border-l-2 border-[var(--surface-border)] pl-3 text-xs text-[var(--text-muted)]">
                                            {{ $participant->apologies }}
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>

                        <div class="border-t border-[var(--surface-border)] p-4">
                            <button type="submit" class="btn btn-secondary btn-sm w-full justify-center">
                                <x-icon name="check" class="h-3.5 w-3.5"/>
                                {{ $registerTaken ? 'Update the register' : 'Take the register' }}
                            </button>
                            <p class="mt-2 text-xs text-[var(--text-muted)]">
                                Unticked means absent, not unknown — once you save, every name here has an
                                answer against it.
                            </p>
                        </div>
                    </form>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">When you are done</h3>
                </div>
                <div class="p-5 text-sm text-[var(--text-body)]">
                    <p>
                        Circulating is a status move on the meeting itself, so that the release of the record
                        and the state of the meeting can never disagree.
                    </p>
                    <a href="{{ route('meetings.show', $meeting) }}" class="btn btn-primary btn-sm mt-4">
                        <x-icon name="mail" class="h-3.5 w-3.5"/> Go to the meeting to circulate
                    </a>
                </div>
            </div>
        </div>
    </div>

</x-layouts.app>
