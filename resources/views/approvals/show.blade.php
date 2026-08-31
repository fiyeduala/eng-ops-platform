{{--
    One approval, in the context of the record it is about.

    The decision form is the most consequential POST in the platform, so this page is built around
    one question: does the person pressing the button know what they are agreeing to? Hence the
    subject is named first, the whole route is shown beneath the form rather than on another page,
    and the buttons carry the stage's own verb — "Acknowledge" on an acknowledgement stage, because
    "Approve" there would misdescribe what is being recorded.

    NOTHING IN THE FORM IDENTIFIES THE ACTOR OR THE STAGE. The actor is the session, the stage is the
    bound route model, and the policy re-checks both against the frozen assignee list before anything
    is written (§84). A hidden user_id would be a claim the server had to either trust or ignore, and
    neither is a good option.

    THE SUBJECT MAY BE UNREADABLE while the approval is visible. Being on a stage is not the same
    permission as being allowed to open the record — confidentiality can be raised after a stage
    opens — so `$canOpenSubject` gates the link separately, and the page never prints subject detail
    it has not been told the viewer may see (§61, §68).

    Expects: $approval, $instance, $subject, $action, $canAct, $canDelegate, $canReassign,
             $canCancel, $canOpenSubject, $people
--}}
@php
    use App\Support\Enums\Decision;
    use App\Support\RecordLinks;

    $subjectUrl = $canOpenSubject ? RecordLinks::for($subject) : null;
    $alreadyDecided = auth()->user() !== null && $approval->hasDecisionFrom(auth()->user());
    $decidable = $canAct && $approval->isPending() && ! $alreadyDecided;
@endphp

<x-layouts.app :title="$approval->displayName()">

    <x-page-header
        :title="$subject?->displayName() ?? 'A record that no longer exists'"
        :description="$approval->displayName().' · '.$action->label()">
        <x-slot:actions>
            <a href="{{ route('approvals.index') }}" class="btn btn-sm btn-ghost">Back to inbox</a>

            @if ($subjectUrl)
                <a href="{{ $subjectUrl }}" class="btn btn-sm btn-secondary">
                    Open {{ strtolower(RecordLinks::label($subject)) }}
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($subject !== null && ! $canOpenSubject)
        <div class="card mb-6 flex items-start gap-3 border-l-4 border-l-slate-400 p-4">
            <x-icon name="lock" class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-muted)]"/>
            <p class="text-sm text-[var(--text-body)]">
                You are on this stage, but the record behind it is not one you may open in full. That
                is not a mistake — its confidentiality may have been raised after you were asked. If
                you need to read it before deciding, say so rather than approving blind.
            </p>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- ---------------------------------------------------------- decision --}}
        <div class="space-y-6 lg:col-span-2">

            @if ($decidable)
                <form method="POST" action="{{ route('approvals.decide', $approval) }}"
                      x-data="{ decision: '{{ old('decision', Decision::Approve->value) }}' }">
                    @csrf

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">{{ $action->verb() }}</h3>
                            @if ($approval->due_at)
                                <span class="text-xs {{ $approval->isOverdue() ? 'font-medium text-rose-600' : 'text-[var(--text-muted)]' }}">
                                    Due {{ $approval->due_at->format('j M Y') }}
                                </span>
                            @endif
                        </div>

                        <div class="space-y-5 p-5">
                            @error('decision')
                                <p class="error" role="alert">{{ $message }}</p>
                            @enderror

                            {{-- The available decisions are the stage's, not a fixed list. An
                                 acknowledgement cannot be rejected: "I have seen it" has one
                                 outcome, and offering Reject there terminates a workflow nobody
                                 meant to stop. --}}
                            <div class="flex flex-wrap gap-2">
                                @php
                                    $choices = [Decision::Approve];

                                    if ($action->allowsRejection()) {
                                        $choices[] = Decision::Reject;
                                        $choices[] = Decision::RequestRevision;
                                    }

                                    $choices[] = Decision::Abstain;

                                    if ($canDelegate) {
                                        $choices[] = Decision::Delegate;
                                    }
                                @endphp

                                @foreach ($choices as $choice)
                                    <label class="cursor-pointer">
                                        <input type="radio" name="decision" value="{{ $choice->value }}"
                                               x-model="decision" class="peer sr-only">
                                        <span class="btn btn-sm btn-ghost ring-1 ring-[var(--surface-border)] peer-checked:ring-2 peer-checked:ring-brand-500"
                                              :class="decision === '{{ $choice->value }}' && 'bg-[var(--surface-muted)] font-medium'">
                                            {{ $choice === Decision::Approve ? $action->verb() : $choice->label() }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>

                            {{-- Delegation --}}
                            @if ($canDelegate)
                                <div x-show="decision === '{{ Decision::Delegate->value }}'" x-cloak>
                                    <x-field label="Hand it to" name="delegate_to" required
                                             help="They receive it in their own inbox, and the record keeps both names — acting on somebody's behalf is normal, and hiding it is not.">
                                        <x-select name="delegate_to" :options="$people" :selected="old('delegate_to')"
                                                  placeholder="Choose a person"/>
                                    </x-field>
                                </div>
                            @endif

                            <x-field label="Comment" name="comment"
                                     help="Required for anything but approval. The author sees this, and it is the only thing telling them what to change.">
                                <x-textarea name="comment" rows="4"
                                            placeholder="What you want changed, or why you are refusing."/>
                            </x-field>

                            @if ($action->producesSignature())
                                <x-field label="Sign as" name="signature_name"
                                         help="Your typed name is the assertion itself. It is stored with your designation as it stands today and a hash of exactly what you signed, so neither can be quietly changed afterwards.">
                                    <x-input name="signature_name" :value="old('signature_name', auth()->user()?->name)"/>
                                </x-field>
                            @endif
                        </div>

                        <div class="flex items-center justify-between gap-3 border-t border-[var(--surface-border)] px-5 py-4">
                            <p class="text-xs text-[var(--text-muted)]">
                                Recorded against your name, permanently.
                            </p>
                            <button type="submit" class="btn btn-primary">
                                <x-icon name="gavel" class="h-3.5 w-3.5"/> Record decision
                            </button>
                        </div>
                    </div>
                </form>
            @else
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">{{ $approval->displayName() }}</h3>
                        <x-badge :tone="$approval->isPending() ? 'warning' : 'neutral'">
                            {{ ucfirst(str_replace('_', ' ', (string) $approval->status)) }}
                        </x-badge>
                    </div>

                    <p class="p-5 text-sm text-[var(--text-body)]">
                        @if ($alreadyDecided)
                            You have already recorded a decision here. It is below, and it stands —
                            a decision is part of the record, not a draft.
                        @elseif (! $approval->isPending())
                            This stage is closed. Nothing further can be recorded on it.
                        @else
                            This stage is waiting on somebody else. You can see it because you are
                            involved, but the decision is not yours to make.
                        @endif
                    </p>
                </div>
            @endif

            {{-- ------------------------------------------------------ decisions so far --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Decisions on this stage</h3>
                    @if ($approval->stage)
                        <span class="text-xs text-[var(--text-muted)]">{{ $approval->stage->quorumLabel() }}</span>
                    @endif
                </div>

                @if ($approval->approvals->isEmpty())
                    <p class="p-5 text-sm text-[var(--text-muted)]">
                        Nobody has recorded a decision here yet.
                    </p>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($approval->approvals as $decision)
                            <li class="px-5 py-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-sm font-medium text-[var(--text-strong)]">
                                        {{ $decision->user?->name ?? 'Someone' }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $decision->resolvedDecision()->badgeClasses() }}">
                                        {{ $decision->resolvedDecision()->label() }}
                                    </span>
                                    @if ($decision->wasDelegated())
                                        <span class="text-xs text-[var(--text-muted)]">
                                            on behalf of {{ $decision->delegatedFrom?->name ?? 'another approver' }}
                                        </span>
                                    @endif
                                    <span class="ml-auto text-xs text-[var(--text-muted)]">
                                        {{ $decision->acted_at?->format('j M Y, H:i') }}
                                    </span>
                                </div>

                                @if ($decision->comment)
                                    <p class="mt-2 whitespace-pre-line text-sm text-[var(--text-body)]">{{ $decision->comment }}</p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- ------------------------------------------------------ the whole route --}}
            @if ($instance)
                <x-workflow-progress :instance="$instance"/>
            @endif
        </div>

        {{-- ---------------------------------------------------------- context --}}
        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">This request</h3>
                </div>

                <dl class="divide-y divide-[var(--surface-border)] text-sm">
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Record</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ RecordLinks::label($subject) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Route</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $instance?->workflow?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Submitted by</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $instance?->starter?->name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Reached you</dt>
                        <dd class="text-right text-[var(--text-body)]">{{ $approval->entered_at?->format('j M Y, H:i') ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Attempt</dt>
                        <dd class="text-right text-[var(--text-body)]">
                            {{ $approval->attempt }}
                            @if ($approval->attempt > 1)
                                <span class="block text-xs text-[var(--text-muted)]">Sent back and resubmitted</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 px-5 py-3">
                        <dt class="text-[var(--text-muted)]">Asked of</dt>
                        <dd class="text-right text-[var(--text-body)]">
                            {{ count($approval->assigneeIds()) }}
                            {{ Str::plural('person', count($approval->assigneeIds())) }}
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- ------------------------------------------------------ administration --}}
            @if ($canReassign || $canCancel)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Administration</h3>
                    </div>

                    <div class="space-y-5 p-5">
                        @if ($canReassign && $approval->isPending())
                            <form method="POST" action="{{ route('approvals.reassign', $approval) }}">
                                @csrf

                                <x-field label="Reassign to" name="assignees"
                                         help="Replaces the current assignee list rather than adding to it — the people taken off can no longer act. Use this when an approval is stuck behind somebody who has left or is away.">
                                    <select name="assignees[]" id="assignees" multiple size="6" class="select">
                                        @foreach ($people as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </x-field>

                                <div class="mt-3">
                                    <label for="reason" class="label">Reason</label>
                                    <x-textarea name="reason" rows="2" placeholder="Why this is being moved."/>
                                    @error('reason')
                                        <p class="error" role="alert">{{ $message }}</p>
                                    @enderror
                                </div>

                                <button type="submit" class="btn btn-secondary btn-sm mt-3">Reassign</button>
                            </form>
                        @endif

                        @if ($canCancel && $instance?->isRunning())
                            <form method="POST" action="{{ route('approvals.cancel', $instance) }}"
                                  class="border-t border-[var(--surface-border)] pt-5"
                                  onsubmit="return confirm('Cancel this approval? The record stays; the route stops.');">
                                @csrf

                                <label for="reason_cancel" class="label">Cancel the whole approval</label>
                                <textarea name="reason" id="reason_cancel" rows="2" class="textarea" required
                                          placeholder="Why this route is being stopped."></textarea>
                                @error('reason')
                                    <p class="error" role="alert">{{ $message }}</p>
                                @enderror

                                <p class="help">
                                    The record is not deleted or reverted — only the approval stops. Everyone
                                    still waiting is told.
                                </p>

                                <button type="submit" class="btn btn-danger btn-sm mt-3">Cancel approval</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
