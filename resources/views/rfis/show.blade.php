{{--
    One query.

    THE ANSWER SITS UNDER THE QUESTION AND NEITHER IS EVER OVERWRITTEN IN PLACE. Answering fills the
    answer; amending it goes through a separate act that demands a reason and writes the change to
    the timeline. That is the difference between a register somebody can rely on in a dispute and a
    text field two people remember differently (§59).

    THE ANSWER FORM IS ONLY DRAWN FOR SOMEBODY WITH `rfi.respond`, and the route re-checks it. This
    is the register's central separation: raising a question and committing the organization to an
    answer are different authorities, and a graduate who may do the first is not thereby able to do
    the second (§10, §84).

    Expects: $rfi, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete, $canSend,
             $canRespond, $canAmend, $canClose
--}}
<x-layouts.app :title="$rfi->number">

    <x-page-header
        :title="$rfi->subject"
        :description="$rfi->number.' · '.($rfi->kind?->label() ?? 'Query').' · '.($rfi->direction?->label() ?? '')">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('rfis.edit', $rfi) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif

            @if ($canSend && ! $rfi->isAnswered())
                <form method="POST" action="{{ route('rfis.send', $rfi) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        <x-icon name="mail" class="h-3.5 w-3.5"/> Mark sent
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    {{-- ---------------------------------------------------------------- state banner --}}
    @php $left = $rfi->daysRemaining(); @endphp

    @if ($rfi->isAnswered())
        <div class="card mb-5 flex items-start gap-3 border-l-4 border-l-emerald-400 p-4">
            <x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600"/>
            <p class="text-sm text-[var(--text-body)]">
                Answered {{ $rfi->answered_at->format('j M Y') }}@if ($rfi->answerer) by {{ $rfi->answerer->name }}@endif.
                The question and the answer are both fixed from here — correcting the answer is a
                recorded amendment, not an edit.
            </p>
        </div>
    @elseif ($rfi->isOverdue())
        <div class="card mb-5 flex items-start gap-3 border-l-4 border-l-rose-400 p-4">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-rose-600"/>
            <p class="text-sm text-[var(--text-body)]">
                An answer was due {{ $rfi->required_by->format('j M Y') }} —
                {{ abs($left ?? 0) }} day{{ abs($left ?? 0) === 1 ? '' : 's' }} ago.
                {{ $rfi->answerIsOwedByUs() ? 'We owe it.' : 'It is owed to us.' }}
            </p>
        </div>
    @elseif ($rfi->answerIsOwedByUs())
        <div class="card mb-5 flex items-start gap-3 border-l-4 border-l-amber-400 p-4">
            <x-icon name="clock" class="mt-0.5 h-4 w-4 shrink-0 text-amber-600"/>
            <p class="text-sm text-[var(--text-body)]">
                This organization owes the answer@if ($rfi->required_by), by {{ $rfi->required_by->format('j M Y') }}@endif.
            </p>
        </div>
    @endif

    @error('answer')
        <div class="card mb-5 border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror
    @error('send')
        <div class="card mb-5 border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror
    @error('close')
        <div class="card mb-5 border-l-4 border-l-rose-400 p-4">
            <p class="text-sm text-[var(--text-body)]">{{ $message }}</p>
        </div>
    @enderror

    <div class="grid gap-5 lg:grid-cols-3">

        {{-- ============================================================ main column --}}
        <div class="space-y-5 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Question</h3>
                </div>

                <div class="p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $rfi->question }}</p>
                </div>

                @if ($canEdit)
                    <x-register.transitions :action="route('rfis.transition', $rfi)"
                                            :transitions="$transitions" noun="query"/>
                @endif
            </div>

            {{-- ------------------------------------------------------ the answer --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Answer</h3>
                </div>

                <div class="p-5">
                    @if ($rfi->isAnswered())
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $rfi->answer }}</p>

                        <p class="mt-3 text-xs text-[var(--text-muted)]">
                            {{ $rfi->answerer?->name ?? 'Unknown' }} · {{ $rfi->answered_at->format('j M Y, H:i') }}
                        </p>

                        @if ($canAmend)
                            <div class="mt-5 border-t border-[var(--surface-border)] pt-5" x-data="{ open: false }">
                                <p class="text-sm text-[var(--text-muted)]">
                                    An answer that turns out to be wrong is amended, not replaced. The
                                    previous wording and your reason both go on the timeline, because a
                                    position the organization already stated cannot quietly stop having
                                    been stated.
                                </p>

                                <button type="button" class="btn btn-secondary btn-sm mt-3"
                                        x-show="! open" @click="open = true">
                                    Amend the answer
                                </button>

                                <form method="POST" action="{{ route('rfis.amend', $rfi) }}"
                                      class="mt-4 space-y-4" x-show="open" x-cloak>
                                    @csrf

                                    <x-field label="Corrected answer" name="answer" required>
                                        <x-textarea name="answer" rows="6" required>{{ old('answer', $rfi->answer) }}</x-textarea>
                                    </x-field>

                                    <x-field label="Why it is being amended" name="reason" required
                                             help="Written to the timeline and visible to everybody who can see this query.">
                                        <x-textarea name="reason" rows="3" required/>
                                    </x-field>

                                    <div class="flex items-center gap-2">
                                        <button type="submit" class="btn btn-danger btn-sm">Amend and record it</button>
                                        <button type="button" class="btn btn-ghost btn-sm" @click="open = false">Never mind</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    @elseif ($canRespond)
                        <form method="POST" action="{{ route('rfis.respond', $rfi) }}" class="space-y-4">
                            @csrf

                            <x-field label="Answer" name="answer" required
                                     help="This is the organization's written position. It is fixed once saved and can only be amended with a recorded reason.">
                                <x-textarea name="answer" rows="7" required>{{ old('answer') }}</x-textarea>
                            </x-field>

                            <div class="space-y-3">
                                <label class="flex items-start gap-3">
                                    <input type="hidden" name="cost_impact" value="0">
                                    <input type="checkbox" name="cost_impact" value="1" class="mt-0.5"
                                           @checked(old('cost_impact', $rfi->cost_impact))>
                                    <span class="text-sm text-[var(--text-body)]">This answer affects cost</span>
                                </label>

                                <label class="flex items-start gap-3">
                                    <input type="hidden" name="schedule_impact" value="0">
                                    <input type="checkbox" name="schedule_impact" value="1" class="mt-0.5"
                                           @checked(old('schedule_impact', $rfi->schedule_impact))>
                                    <span class="text-sm text-[var(--text-body)]">This answer affects the programme</span>
                                </label>
                            </div>

                            <x-field label="Impact notes" name="impact_notes"
                                     help="If either box is ticked, this is what the commercial team will read. A change request usually follows.">
                                <x-textarea name="impact_notes" rows="3">{{ old('impact_notes', $rfi->impact_notes) }}</x-textarea>
                            </x-field>

                            <button type="submit" class="btn btn-primary">Record the answer</button>
                        </form>
                    @else
                        <p class="text-sm text-[var(--text-muted)]">
                            Not answered yet. Answering is held by the people qualified to state this
                            organization's technical position, which is a different permission from
                            editing the query.
                        </p>
                    @endif
                </div>
            </div>

            {{-- ------------------------------------------------------ close --}}
            @if ($canClose && $rfi->isAnswered() && ! $rfi->isClosed())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Close this query</h3>
                    </div>

                    <form method="POST" action="{{ route('rfis.close', $rfi) }}" class="space-y-4 p-5">
                        @csrf

                        <p class="text-sm text-[var(--text-muted)]">
                            Closing says the answer has been accepted and no further action is expected.
                            It stays on the register and stays readable — closing is not deleting.
                        </p>

                        <x-field label="Closing note" name="note">
                            <x-textarea name="note" rows="2"
                                        placeholder="Optional. e.g. Answer incorporated into Rev C of the general arrangement."/>
                        </x-field>

                        <button type="submit" class="btn btn-secondary btn-sm">Close it</button>
                    </form>
                </div>
            @endif

            <x-timeline :entries="$timeline" empty="Nothing has happened to this query since it was raised."/>
        </div>

        {{-- ============================================================ side column --}}
        <div class="space-y-5">

            @if ($workflowInstance)
                <x-workflow-progress :instance="$workflowInstance"/>
            @endif

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <x-register.fact label="Number" :value="$rfi->number" mono/>
                    <x-register.fact label="Kind" :value="$rfi->kind?->label()"/>

                    <x-register.fact label="Direction">
                        {{ $rfi->direction?->label() ?? '—' }}
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $rfi->answerIsOwedByUs() ? 'We owe the answer' : 'They owe the answer' }}
                        </span>
                    </x-register.fact>

                    <x-register.fact label="Party" :value="$rfi->party_name"/>

                    <x-register.fact label="Status">
                        @if ($rfi->status)
                            <x-badge :tone="$rfi->status->tone()">{{ $rfi->status->label }}</x-badge>
                        @else
                            —
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Project">
                        @if ($rfi->project)
                            <a href="{{ route('projects.show', $rfi->project) }}" class="hover:text-brand-600">
                                {{ $rfi->project->displayName() }}
                            </a>
                        @else
                            —
                        @endif
                    </x-register.fact>

                    <x-register.fact label="Discipline" :value="$rfi->discipline?->name"/>
                    <x-register.fact label="Confidentiality" :value="$rfi->confidentiality?->label()"/>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Dates & impact</h3>
                </div>

                <dl class="space-y-3 p-5 text-sm">
                    <x-register.fact label="Raised by" :value="$rfi->raiser?->name"/>
                    <x-register.fact label="Raised on" :value="$rfi->raised_on?->format('j M Y')"/>
                    <x-register.fact label="Answer required by" :value="$rfi->required_by?->format('j M Y')"/>
                    <x-register.fact label="Answered"
                                     :value="$rfi->answered_at?->format('j M Y') ?? 'Not yet'"/>

                    <x-register.fact label="Impact">
                        @if ($rfi->cost_impact || $rfi->schedule_impact)
                            <span class="flex flex-wrap gap-1">
                                @if ($rfi->cost_impact) <x-badge tone="danger">Cost</x-badge> @endif
                                @if ($rfi->schedule_impact) <x-badge tone="warning">Programme</x-badge> @endif
                            </span>
                        @else
                            None flagged
                        @endif
                    </x-register.fact>

                    @if (filled($rfi->impact_notes))
                        <x-register.fact label="Impact notes">
                            <span class="block whitespace-pre-line">{{ $rfi->impact_notes }}</span>
                        </x-register.fact>
                    @endif
                </dl>
            </div>

            @if ($canDelete)
                <div class="card border-red-200">
                    <div class="card-header">
                        <h3 class="card-title">Delete this query</h3>
                    </div>

                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            Only ever possible before it has been answered. Once an answer exists the
                            record is evidence, and evidence is closed rather than removed.
                        </p>

                        <x-delete-form :action="route('rfis.destroy', $rfi)"
                                       label="Delete this query"
                                       confirm="Delete this query? Its history is kept, but it leaves the register."/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
