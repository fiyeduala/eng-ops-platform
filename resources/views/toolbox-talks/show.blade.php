{{--
    One toolbox talk.

    THE ATTENDANCE SHEET IS THE RECORD and it is the bottom half of this page. Everything above it —
    topic, content, key points — is the easy half; what an inspector asks for, and what a company
    needs eighteen months later when somebody says nobody told them, is the list of names.

    SIGNING IS THE ATTENDEE'S OWN ACT. The button appears against this viewer's own row and no other,
    and whether it appears at all is the policy's answer rather than this template's guess. A
    supervisor ticking the whole list on everyone's behalf is precisely what a signature exists to
    rule out (§84).

    Expects: $talk, $myAttendance, $canSignMine, $timeline, $transitions, $workflowInstance,
             $people, $canEdit, $canDelete, $canRecord
--}}
<x-layouts.app :title="$talk->displayName()">

    <x-page-header :title="$talk->title" :description="$talk->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('toolbox-talks.edit', $talk) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            <a href="{{ route('toolbox-talks.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('attendees') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('sign') <p class="error mb-4">{{ $message }}</p> @enderror

    @unless ($talk->wasHeld())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm text-amber-800 dark:text-amber-200">
                This talk has not been recorded as given. Until the attendance sheet is filled in it
                is a plan, not a record.
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
                    <h3 class="card-title">The briefing</h3>
                    @if ($talk->topic)
                        <x-badge tone="neutral">{{ $talk->topic }}</x-badge>
                    @endif
                </div>
                <div class="space-y-5 p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                        {{ $talk->content ?: 'Nothing was written down beyond the title.' }}
                    </p>

                    @if ($talk->key_points)
                        <div class="rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] p-4">
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Key points</p>
                            <p class="mt-1 whitespace-pre-line text-sm text-[var(--text-body)]">
                                {{ $talk->key_points }}
                            </p>
                        </div>
                    @endif

                    @if ($talk->document)
                        <x-register.fact label="Handout">
                            <a href="{{ route('documents.show', $talk->document) }}"
                               class="text-brand-600 hover:underline">
                                {{ $talk->document->displayName() }}
                            </a>
                        </x-register.fact>
                    @endif
                </div>
            </div>

            {{-- The sheet. Before the talk is recorded this is a form; afterwards it is the list,
                 with each person's own signature beside their name. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Who was there</h3>
                    <span class="text-sm text-[var(--text-muted)]">
                        {{ $talk->attendees->count() }}
                        {{ Str::plural('name', $talk->attendees->count()) }}
                        @if ($talk->attendees->isNotEmpty())
                            · {{ $talk->attendees->whereNotNull('signed_at')->count() }} signed
                        @endif
                    </span>
                </div>

                @if ($talk->attendees->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--text-muted)]">
                                <tr>
                                    <th class="px-4 py-3">Name</th>
                                    <th class="px-4 py-3">Company</th>
                                    <th class="px-4 py-3">Trade</th>
                                    <th class="px-4 py-3">Signed</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--surface-border)]">
                                @foreach ($talk->attendees as $attendee)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-[var(--text-strong)]">
                                            {{ $attendee->displayName() }}
                                            @unless ($attendee->user_id)
                                                <span class="ml-1 text-xs font-normal text-[var(--text-muted)]">
                                                    no account
                                                </span>
                                            @endunless
                                        </td>
                                        <td class="px-4 py-3 text-[var(--text-muted)]">{{ $attendee->company ?: '—' }}</td>
                                        <td class="px-4 py-3 text-[var(--text-muted)]">{{ $attendee->trade ?: '—' }}</td>
                                        <td class="px-4 py-3">
                                            @if ($attendee->hasSigned())
                                                <x-badge tone="success">
                                                    {{ $attendee->signed_at->format('j M H:i') }}
                                                </x-badge>
                                            @elseif ($canSignMine && $myAttendance?->is($attendee))
                                                {{-- Offered against this viewer's own row only. --}}
                                                <form method="POST"
                                                      action="{{ route('toolbox-talks.attendees.sign', [$talk, $attendee]) }}">
                                                    @csrf
                                                    <button type="submit" class="btn btn-secondary btn-sm">
                                                        Sign for myself
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-[var(--text-muted)]">Not signed</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif (! $canRecord)
                    <div class="p-5">
                        <x-empty-state icon="users" title="Nobody recorded yet"
                                       description="The attendance sheet is filled in by whoever gave the talk."/>
                    </div>
                @endif
            </div>

            @if ($canRecord)
                {{-- Recording replaces the sheet with what is on this screen, and stamps the talk
                     as held. `held_at` is asked for rather than stamped because the sheet is typed
                     up at the end of the shift, not at 06:40 when the talk was actually given — a
                     register that recorded the moment of data entry would record a time everybody
                     on site knows is wrong. --}}
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            {{ $talk->wasHeld() ? 'Correct the sheet' : 'Record the talk' }}
                        </h3>
                    </div>
                    <form method="POST" action="{{ route('toolbox-talks.record', $talk) }}"
                          x-data="attendanceSheet(@js($talk->attendees->map(fn ($a) => [
                              'user_id' => $a->user_id ?? '',
                              'person_name' => $a->person_name ?? '',
                              'company' => $a->company ?? '',
                              'trade' => $a->trade ?? '',
                          ])->all()))">
                        @csrf

                        <div class="space-y-5 p-5">
                            <x-field label="Held at" name="held_at"
                                     help="Leave it blank and now is used.">
                                <x-input type="datetime-local" name="held_at"
                                         :value="old('held_at', $talk->held_at?->format('Y-m-d\TH:i'))"/>
                            </x-field>

                            <div class="space-y-3">
                                <template x-for="(row, i) in rows" :key="i">
                                    <div class="grid gap-3 sm:grid-cols-12">
                                        <div class="sm:col-span-4">
                                            <input type="text" class="input" maxlength="200"
                                                   :name="`attendees[${i}][person_name]`"
                                                   x-model="row.person_name"
                                                   placeholder="Name">
                                        </div>
                                        <div class="sm:col-span-3">
                                            <select class="input" :name="`attendees[${i}][user_id]`"
                                                    x-model="row.user_id">
                                                <option value="">No account</option>
                                                @foreach ($people as $id => $name)
                                                    <option value="{{ $id }}">{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="sm:col-span-2">
                                            <input type="text" class="input" maxlength="200"
                                                   :name="`attendees[${i}][company]`"
                                                   x-model="row.company" placeholder="Company">
                                        </div>
                                        <div class="sm:col-span-2">
                                            <input type="text" class="input" maxlength="120"
                                                   :name="`attendees[${i}][trade]`"
                                                   x-model="row.trade" placeholder="Trade">
                                        </div>
                                        <div class="sm:col-span-1 sm:text-right">
                                            <button type="button" class="btn btn-ghost btn-sm"
                                                    @click="rows.splice(i, 1)">&times;</button>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" class="btn btn-secondary btn-sm" @click="add()">
                                    Add a row
                                </button>
                                <button type="submit" class="btn btn-primary btn-sm">
                                    {{ $talk->wasHeld() ? 'Save the sheet' : 'Record as delivered' }}
                                </button>
                                <span class="text-sm text-[var(--text-muted)]">
                                    <span x-text="named"></span> named. Rows with no name are dropped;
                                    saving replaces the sheet.
                                </span>
                            </div>
                        </div>
                    </form>
                </div>
            @endif

            <x-register.transitions :action="route('toolbox-talks.transition', $talk)"
                                    :transitions="$transitions" noun="talk"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this talk yet."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$talk->number" mono/>
                    <x-register.fact label="Status">
                        @if ($talk->status)
                            <x-badge :tone="$talk->status->tone()">{{ $talk->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Planned</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Topic" :value="$talk->topic"/>
                    <x-register.fact label="Held" :value="$talk->held_at?->format('j M Y H:i')"/>
                    <x-register.fact label="Where" :value="$talk->location"/>
                    <x-register.fact label="How long"
                                     :value="$talk->duration_minutes ? $talk->duration_minutes.' minutes' : null"/>
                    <x-register.fact label="Presented by" :value="$talk->presenterLabel()"/>
                    <x-register.fact label="Project" :value="$talk->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$talk->discipline?->name"/>
                    <x-register.fact label="Department" :value="$talk->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$talk->confidentiality?->label()"/>
                    <x-register.fact label="Planned by" :value="$talk->creator?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            The attendance sheet goes with it. A talk that was actually given is the
                            evidence it was given — delete the plan, not the record.
                        </p>
                        <x-delete-form :action="route('toolbox-talks.destroy', $talk)"
                                       label="Delete this talk"
                                       confirm="Delete it and its attendance sheet?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
