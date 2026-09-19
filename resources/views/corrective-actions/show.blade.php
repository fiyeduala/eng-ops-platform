{{--
    One corrective action.

    THE LOOP IS THREE HANDS AND THE PAGE SHOWS ALL THREE. Somebody raises it, somebody does it and
    says so in writing, and somebody ELSE says whether it worked. Completion takes `edit` and is
    narrowed by the policy to the assignee; verification takes `corrective_action.verify` and is
    refused to the person who completed it. An action closed by the person asked to do it proves
    nothing at all, and this page is laid out so that is obvious.

    THE SOURCE LINK IS CONDITIONAL AND THE CONDITION IS A POLICY CALL the controller already made.
    `$source` arrives as null when this viewer could not open the record it came from — which is the
    normal case for a supervisor asked to fit a guard rail after a confidential injury (§68).

    EFFECTIVENESS IS ASKED AT VERIFICATION, NOT AT COMPLETION. "Not effective" is a legitimate answer
    and the register keeps it: a company whose actions are all effective is a company not checking.

    Expects: $action, $source, $timeline, $transitions, $workflowInstance, $effectiveness, $people,
             $canEdit, $canDelete, $canAssign, $canComplete, $canVerify, $canCancel
--}}
<x-layouts.app :title="$action->displayName()">

    <x-page-header :title="$action->title" :description="$action->number">
        <x-slot:actions>
            @if ($canEdit)
                <a href="{{ route('corrective-actions.edit', $action) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Edit
                </a>
            @endif
            <a href="{{ route('corrective-actions.index') }}" class="btn btn-ghost btn-sm">Back to the register</a>
        </x-slot:actions>
    </x-page-header>

    @error('completion_notes') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('effectiveness') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('assigned_to') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('reason') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">What good looks like</h3>
                    @if ($action->priority)
                        <x-badge tone="neutral">{{ $action->priority }}</x-badge>
                    @endif
                </div>
                <div class="space-y-5 p-5">
                    <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                        {{ $action->description ?: 'No description was recorded.' }}
                    </p>

                    @if ($source)
                        <div class="border-t border-[var(--surface-border)] pt-5">
                            <p class="text-xs font-medium uppercase tracking-wide text-[var(--text-muted)]">
                                Raised from
                            </p>
                            <p class="mt-2 text-sm">
                                <span class="font-mono text-[var(--text-muted)]">
                                    {{ $source->number ?? '' }}
                                </span>
                                <span class="text-[var(--text-body)]">{{ $source->title ?? '' }}</span>
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Done</h3>
                    @if ($action->isComplete())
                        <x-badge tone="success">{{ $action->completed_on?->format('j M Y') }}</x-badge>
                    @endif
                </div>

                @if ($action->isComplete())
                    <div class="space-y-2 p-5">
                        <p class="text-xs text-[var(--text-muted)]">
                            Marked done by {{ $action->completer?->name ?? 'somebody' }}
                            on {{ $action->completed_on?->format('j M Y') }}.
                        </p>
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                            {{ $action->completion_notes ?: 'No note was left.' }}
                        </p>
                    </div>
                @elseif ($canComplete)
                    <form method="POST" action="{{ route('corrective-actions.complete', $action) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <x-field label="What you did" name="completion_notes" required
                                 help="The verifier reads this before deciding whether it worked. &quot;Done&quot; is not evidence.">
                            <x-textarea name="completion_notes" rows="4" required>{{ old('completion_notes') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-primary">Mark it done</button>
                    </form>
                @else
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        Not yet done. Only the person it is assigned to can mark it complete.
                    </p>
                @endif
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Did it work</h3>
                    @if ($action->effectiveness)
                        <x-badge tone="success">{{ $action->effectiveness }}</x-badge>
                    @endif
                </div>

                @if ($action->isVerified())
                    <div class="space-y-2 p-5">
                        <p class="text-xs text-[var(--text-muted)]">
                            Verified by {{ $action->verifier?->name ?? 'somebody' }}
                            on {{ $action->verified_at?->format('j M Y') }}.
                        </p>
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">
                            {{ $action->verification_notes ?: 'No note was left.' }}
                        </p>
                    </div>
                @elseif ($canVerify && $action->isComplete())
                    <form method="POST" action="{{ route('corrective-actions.verify', $action) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <p class="text-sm text-[var(--text-muted)]">
                            You are answering one question: has the thing that caused this stopped
                            being able to cause it? "Not effective" is a real answer and the
                            register keeps it.
                        </p>
                        <x-field label="Effectiveness" name="effectiveness" required>
                            <x-select name="effectiveness" :options="$effectiveness"
                                      :selected="old('effectiveness')" placeholder="Choose one" required/>
                        </x-field>
                        <x-field label="What you checked" name="verification_notes">
                            <x-textarea name="verification_notes" rows="3">{{ old('verification_notes') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-primary">Record the verification</button>
                    </form>
                @else
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        @if (! $action->isComplete())
                            Verification comes after the work is done.
                        @else
                            Awaiting verification by somebody other than the person who did it.
                        @endif
                    </p>
                @endif
            </div>

            @if ($canAssign)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Hand it over</h3>
                    </div>
                    <form method="POST" action="{{ route('corrective-actions.assign', $action) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <x-field label="Assigned to" name="assigned_to"
                                 help="Leave blank to return it to the pool — an action pointing at somebody who has left is worse than an unassigned one.">
                            <x-select name="assigned_to" :options="$people"
                                      :selected="old('assigned_to', $action->assigned_to)"
                                      placeholder="Nobody"/>
                        </x-field>
                        <button type="submit" class="btn btn-secondary">Reassign</button>
                    </form>
                </div>
            @endif

            @if ($canCancel && ! $action->isVerified())
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Withdraw</h3>
                    </div>
                    <form method="POST" action="{{ route('corrective-actions.cancel', $action) }}"
                          class="space-y-4 p-5">
                        @csrf
                        <p class="text-sm text-[var(--text-muted)]">
                            Withdrawing is not closing. The action ends as cancelled and is counted
                            nowhere as a success — which is the honest record when a decision is
                            overtaken rather than carried out.
                        </p>
                        <x-field label="Why is it being withdrawn" name="reason" required>
                            <x-textarea name="reason" rows="3" required>{{ old('reason') }}</x-textarea>
                        </x-field>
                        <button type="submit" class="btn btn-secondary">Withdraw the action</button>
                    </form>
                </div>
            @endif

            <x-register.transitions :action="route('corrective-actions.transition', $action)"
                                    :transitions="$transitions" noun="action"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened since this action was raised."/>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$action->number" mono/>
                    <x-register.fact label="Status">
                        @if ($action->status)
                            <x-badge :tone="$action->status->tone()">{{ $action->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Open</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Type" :value="$action->action_type"/>
                    <x-register.fact label="Priority" :value="$action->priority"/>
                    <x-register.fact label="Assigned to" :value="$action->assignee?->name"/>
                    <x-register.fact label="Due" :value="$action->due_on?->format('j M Y')"/>
                    <x-register.fact label="Completed" :value="$action->completed_on?->format('j M Y')"/>
                    <x-register.fact label="Verified" :value="$action->verified_at?->format('j M Y')"/>
                    <x-register.fact label="Project" :value="$action->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$action->discipline?->name"/>
                    <x-register.fact label="Department" :value="$action->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$action->confidentiality?->label()"/>
                    <x-register.fact label="Raised by" :value="$action->creator?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            Withdrawing is almost always the right act instead of this — it keeps
                            the record that something was once thought necessary.
                        </p>
                        <x-delete-form :action="route('corrective-actions.destroy', $action)"
                                       label="Delete this action"
                                       :confirm="'Delete '.$action->number.'?'"/>
                    </div>
                </div>
            @endif
        </div>
    </div>


    {{-- What this company recorded in its own fields (§73). --}}
    <x-custom-fields.facts :record="$action" class="card mt-5"/>

</x-layouts.app>
