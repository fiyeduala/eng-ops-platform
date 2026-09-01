{{--
    One permit to work.

    THE STATE IS THE FIRST THING ON THE PAGE AND IT IS BIG. Everywhere else in this application a
    status badge in the corner is enough; here the difference between "live" and "suspended" is the
    difference between a crew working and a crew standing down, and somebody opening this on a phone
    at the barrier has to know which within one second of the page appearing.

    EVERY ACT IS ITS OWN FORM BEHIND ITS OWN PERMISSION, and not one of them is on the edit screen.
    Issue, reject, accept, suspend, resume, close and cancel are seven separate posts by seven
    possibly different people, because each one is a signature and the register has to be able to say
    who made it (§59, §84).

    SUSPENDING IS THE EASIEST THING ON THIS PAGE and resuming is deliberately not. Stopping work is
    always allowed to be quick; restarting it takes the authority that issued the permit.

    Expects: $permit, $timeline, $transitions, $workflowInstance, $canEdit, $canDelete, $canIssue,
             $canReject, $canAccept, $canSuspend, $canResume, $canClose, $canCancel,
             $canRecordGasTest
--}}
<x-layouts.app :title="$permit->number">

    <x-page-header :title="$permit->number" :description="$permit->title">
        <x-slot:actions>
            @if ($canEdit && ! $permit->isClosedOut())
                <a href="{{ route('permits.edit', $permit) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
            <a href="{{ route('permits.index') }}" class="btn btn-ghost btn-sm">All permits</a>
        </x-slot:actions>
    </x-page-header>

    {{-- The banner. One of these always renders, and the order is the order the conditions
         override each other: cancelled beats closed beats suspended beats expired. --}}
    @if ($permit->cancelled_at)
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm font-semibold text-[var(--text-strong)]">Cancelled</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                Withdrawn {{ $permit->cancelled_at->format('j M Y H:i') }}.
                @if ($permit->cancellation_reason) {{ $permit->cancellation_reason }} @endif
            </p>
        </div>
    @elseif ($permit->closed_at)
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/60 dark:bg-emerald-950/40">
            <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">Closed out</p>
            <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
                Handed back {{ $permit->closed_at->format('j M Y H:i') }}
                @if ($permit->closer) by {{ $permit->closer->name }} @endif.
            </p>
        </div>
    @elseif ($permit->isSuspended())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm font-semibold text-rose-800 dark:text-rose-200">Suspended — work must stop</p>
            <p class="mt-1 text-sm text-rose-800 dark:text-rose-200">
                Stopped {{ $permit->suspended_at?->format('j M Y H:i') }}.
                {{ $permit->suspension_reason }}
            </p>
        </div>
    @elseif ($permit->hasExpired() && $permit->isIssued())
        <div class="mb-6 rounded-md border border-rose-200 bg-rose-50 px-4 py-3 dark:border-rose-900/60 dark:bg-rose-950/40">
            <p class="text-sm font-semibold text-rose-800 dark:text-rose-200">Expired and still open</p>
            <p class="mt-1 text-sm text-rose-800 dark:text-rose-200">
                The window closed {{ $permit->valid_to?->format('j M Y H:i') }}. If the work is
                finished, close the permit out. If it is not, it needs a new one.
            </p>
        </div>
    @elseif ($permit->isLive())
        <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-900/60 dark:bg-emerald-950/40">
            <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">Live — work is authorised</p>
            <p class="mt-1 text-sm text-emerald-800 dark:text-emerald-200">
                Until {{ $permit->valid_to?->format('j M Y H:i') ?: 'no stated end' }}, accepted by
                {{ $permit->acceptor?->name ?: 'nobody yet' }}.
            </p>
        </div>
    @elseif ($permit->isIssued())
        <div class="mb-6 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/40">
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">Issued, not yet accepted</p>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-200">
                Nobody has taken this permit on. Work must not start until the performing supervisor
                accepts it.
            </p>
        </div>
    @else
        <div class="mb-6 rounded-md border border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-3">
            <p class="text-sm font-semibold text-[var(--text-strong)]">Awaiting issue</p>
            <p class="mt-1 text-sm text-[var(--text-muted)]">
                Requested by {{ $permit->requester?->name ?: 'somebody no longer on the system' }}.
                No work is authorised by this document yet.
            </p>
        </div>
    @endif

    @error('issue') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('reason') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('gas_test_result') <p class="error mb-4">{{ $message }}</p> @enderror
    @error('closure_notes') <p class="error mb-4">{{ $message }}</p> @enderror

    @if ($workflowInstance)
        <div class="mb-6">
            <x-workflow-progress :instance="$workflowInstance"/>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">The work</h3>
                    <p class="card-description">
                        {{ $permit->permitType?->name ?: 'Untyped permit' }}
                        @if ($permit->location) · {{ $permit->location }} @endif
                    </p>
                </div>
                <div class="space-y-5 p-5">
                    <p class="whitespace-pre-line text-sm leading-relaxed text-[var(--text-body)]">{{ $permit->description }}</p>

                    <div class="grid gap-4 border-t border-[var(--surface-border)] pt-4 text-sm sm:grid-cols-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Equipment</p>
                            <p class="mt-1 text-[var(--text-body)]">{{ $permit->equipment ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">Isolation</p>
                            <p class="mt-1 font-mono text-[var(--text-body)]">{{ $permit->isolation_reference ?: '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-[var(--text-muted)]">People working</p>
                            <p class="mt-1 tabular-nums text-[var(--text-body)]">{{ $permit->worker_count ?: '—' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- The checklist. ONE FORM PER LINE, because the question after an incident is never
                 "was the checklist done" but "who confirmed the space had been purged, and when" —
                 and a single tick-all button answers that with one name for every line. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Precautions</h3>
                    <p class="card-description">
                        @if ($permit->precautions->isEmpty())
                            Nothing was copied from a permit type.
                        @else
                            {{ $permit->precautions->where('is_confirmed', true)->count() }}
                            of {{ $permit->precautions->count() }} confirmed.
                        @endif
                    </p>
                </div>

                @if ($permit->precautions->isEmpty())
                    <div class="p-5">
                        <x-empty-state icon="lock" title="No precautions listed"
                                       description="This permit was raised without a type, or against a type with an empty template. Confirm the controls another way before issuing it."/>
                    </div>
                @else
                    <ul class="divide-y divide-[var(--surface-border)]">
                        @foreach ($permit->precautions as $precaution)
                            <li class="p-5">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5">
                                        @if ($precaution->is_confirmed)
                                            <x-icon name="check" class="h-4 w-4 text-emerald-600"/>
                                        @else
                                            <span class="block h-4 w-4 rounded border border-[var(--surface-border)]"></span>
                                        @endif
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-[var(--text-body)]">
                                            {{ $precaution->description }}
                                            @unless ($precaution->is_required)
                                                <span class="ml-1 text-xs text-[var(--text-muted)]">(optional)</span>
                                            @endunless
                                        </p>

                                        @if ($precaution->is_confirmed)
                                            <p class="mt-1 text-xs text-[var(--text-muted)]">
                                                Confirmed by {{ $precaution->confirmer?->name ?: 'somebody no longer on the system' }}
                                                @if ($precaution->confirmed_at) on {{ $precaution->confirmed_at->format('j M Y H:i') }} @endif
                                            </p>
                                            @if ($precaution->comment)
                                                <p class="mt-1 text-xs italic text-[var(--text-muted)]">{{ $precaution->comment }}</p>
                                            @endif
                                        @elseif (! $permit->isClosedOut())
                                            @can('confirm', $precaution)
                                                <form method="POST"
                                                      action="{{ route('permits.precautions.confirm', [$permit, $precaution]) }}"
                                                      class="mt-2 flex flex-wrap items-center gap-2">
                                                    @csrf
                                                    <input type="text" name="comment" class="input h-8 max-w-xs text-xs"
                                                           placeholder="Reading, note or reference (optional)">
                                                    <button type="submit" class="btn btn-secondary btn-sm">
                                                        I have checked this
                                                    </button>
                                                </form>
                                            @endcan
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Shown whenever the type demands one or a reading has already been taken. The
                 result is free text and not a pass/fail, because "O₂ 20.9%, LEL 0%, H₂S 0ppm" is
                 the part anybody would want to read back eighteen months later. --}}
            @if ($permit->gas_test_required || $permit->gas_test_result)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Gas test</h3>
                        <p class="card-description">
                            @if ($permit->gas_test_required)
                                This type of permit cannot be issued without a recorded result.
                            @else
                                Recorded although this type does not demand it.
                            @endif
                        </p>
                    </div>
                    <div class="space-y-4 p-5">
                        @if ($permit->gas_test_result)
                            <div>
                                <p class="font-mono text-sm text-[var(--text-strong)]">{{ $permit->gas_test_result }}</p>
                                <p class="mt-1 text-xs text-[var(--text-muted)]">
                                    Taken by {{ $permit->gasTester?->name ?: 'somebody no longer on the system' }}
                                    @if ($permit->gas_tested_at) on {{ $permit->gas_tested_at->format('j M Y H:i') }} @endif
                                </p>
                            </div>
                        @else
                            <p class="text-sm text-[var(--text-muted)]">No reading has been taken.</p>
                        @endif

                        @if ($canRecordGasTest)
                            <form method="POST" action="{{ route('permits.gas-test', $permit) }}"
                                  class="flex flex-wrap items-end gap-3 border-t border-[var(--surface-border)] pt-4">
                                @csrf
                                <div class="min-w-0 flex-1">
                                    <label for="gas_test_result" class="label">Readings</label>
                                    <x-input name="gas_test_result" required
                                             placeholder="e.g. O2 20.9%, LEL 0%, H2S 0ppm, CO 0ppm"/>
                                </div>
                                <button type="submit" class="btn btn-secondary">Record the test</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Seven acts, seven forms, seven permissions. Nothing here is a status dropdown:
                 each one records a named person against a moment, and that record is what the
                 permit is for. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Acts on this permit</h3>
                    <p class="card-description">Each of these is signed in your name.</p>
                </div>
                <div class="space-y-5 p-5">

                    @if ($canIssue && ! $permit->isIssued() && ! $permit->isClosedOut())
                        <form method="POST" action="{{ route('permits.issue', $permit) }}"
                              x-data="{ armed: false }"
                              @submit="if (!armed) { $event.preventDefault(); armed = true; }">
                            @csrf
                            <p class="text-sm text-[var(--text-body)]">
                                Issuing authorises this work. You are confirming the precautions have
                                been checked and the isolations are in place.
                            </p>
                            @unless ($permit->requiredPrecautionsConfirmed())
                                <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                                    Required precautions are still unconfirmed — this will be refused.
                                </p>
                            @endunless
                            <button type="submit" class="btn btn-primary btn-sm mt-3"
                                    x-text="armed ? 'Click again to issue' : 'Issue this permit'">Issue this permit</button>
                        </form>
                    @endif

                    @if ($canReject && ! $permit->isIssued() && ! $permit->isClosedOut())
                        <form method="POST" action="{{ route('permits.reject', $permit) }}"
                              class="space-y-2 border-t border-[var(--surface-border)] pt-5">
                            @csrf
                            <label for="reject_reason" class="label">Refuse the request</label>
                            <x-textarea name="reason" id="reject_reason" rows="2" required
                                        placeholder="Why this cannot be authorised as asked."></x-textarea>
                            <button type="submit" class="btn btn-ghost btn-sm">Refuse</button>
                        </form>
                    @endif

                    @if ($canAccept && $permit->isIssued() && ! $permit->isAccepted() && ! $permit->isClosedOut())
                        <form method="POST" action="{{ route('permits.accept', $permit) }}"
                              x-data="{ armed: false }"
                              @submit="if (!armed) { $event.preventDefault(); armed = true; }"
                              class="border-t border-[var(--surface-border)] pt-5">
                            @csrf
                            <p class="text-sm text-[var(--text-body)]">
                                Accepting takes responsibility for working inside this permit. Your
                                crew may not start until you do.
                            </p>
                            <button type="submit" class="btn btn-primary btn-sm mt-3"
                                    x-text="armed ? 'Click again to accept' : 'Accept this permit'">Accept this permit</button>
                        </form>
                    @endif

                    {{-- Stopping work is always the quickest thing on the page: one box, one
                         button, no confirmation step. Restarting it is not. --}}
                    @if ($canSuspend && $permit->isIssued() && ! $permit->isSuspended() && ! $permit->isClosedOut())
                        <form method="POST" action="{{ route('permits.suspend', $permit) }}"
                              class="space-y-2 border-t border-[var(--surface-border)] pt-5">
                            @csrf
                            <label for="suspend_reason" class="label">Stop the work</label>
                            <x-textarea name="reason" id="suspend_reason" rows="2" required
                                        placeholder="What changed. Say it plainly — the crew will read this."></x-textarea>
                            <button type="submit" class="btn btn-sm border border-rose-300 bg-rose-50 text-rose-800 hover:bg-rose-100 dark:border-rose-900/60 dark:bg-rose-950/40 dark:text-rose-200">
                                Suspend this permit
                            </button>
                        </form>
                    @endif

                    @if ($canResume && $permit->isSuspended())
                        <form method="POST" action="{{ route('permits.resume', $permit) }}"
                              x-data="{ armed: false }"
                              @submit="if (!armed) { $event.preventDefault(); armed = true; }"
                              class="border-t border-[var(--surface-border)] pt-5">
                            @csrf
                            <p class="text-sm text-[var(--text-body)]">
                                Resuming re-authorises the work. Only do this once whatever caused the
                                suspension has actually been dealt with.
                            </p>
                            <button type="submit" class="btn btn-secondary btn-sm mt-3"
                                    x-text="armed ? 'Click again to resume' : 'Resume the work'">Resume the work</button>
                        </form>
                    @endif

                    @if ($canClose && $permit->isIssued() && ! $permit->isClosedOut())
                        <form method="POST" action="{{ route('permits.close', $permit) }}"
                              class="space-y-2 border-t border-[var(--surface-border)] pt-5">
                            @csrf
                            <label for="closure_notes" class="label">Hand the permit back</label>
                            <x-textarea name="closure_notes" id="closure_notes" rows="2"
                                        placeholder="Area left safe, guards refitted, isolations removed. Optional."></x-textarea>
                            <button type="submit" class="btn btn-secondary btn-sm">Close this permit out</button>
                        </form>
                    @endif

                    @if ($canCancel && ! $permit->isClosedOut())
                        <form method="POST" action="{{ route('permits.cancel', $permit) }}"
                              class="space-y-2 border-t border-[var(--surface-border)] pt-5">
                            @csrf
                            <label for="cancel_reason" class="label">Withdraw the permit</label>
                            <x-textarea name="reason" id="cancel_reason" rows="2" required
                                        placeholder="Why this permit is being withdrawn rather than closed."></x-textarea>
                            <button type="submit" class="btn btn-ghost btn-sm">Cancel this permit</button>
                        </form>
                    @endif

                    @if ($permit->isClosedOut())
                        <p class="text-sm text-[var(--text-muted)]">
                            This permit is finished. Nothing further can be done to it.
                            @if ($permit->closure_notes)
                                <span class="mt-2 block italic">{{ $permit->closure_notes }}</span>
                            @endif
                        </p>
                    @endif
                </div>
            </div>

            <x-register.transitions :action="route('permits.transition', $permit)"
                                    :transitions="$transitions" noun="permit"/>

            <x-timeline :entries="$timeline" title="History"
                        empty="Nothing has happened to this permit yet."/>
        </div>

        <div class="space-y-6">
            {{-- The signature block. Four names, four moments, in the order they happen — this is
                 the half of the permit an investigation reads first. --}}
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Who signed what</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Requested by">
                        {{ $permit->requester?->name ?: '—' }}
                        <span class="block text-xs text-[var(--text-muted)]">
                            {{ $permit->created_at?->format('j M Y H:i') }}
                        </span>
                    </x-register.fact>
                    <x-register.fact label="Issued by">
                        @if ($permit->isIssued())
                            {{ $permit->issuer?->name ?: '—' }}
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $permit->issued_at?->format('j M Y H:i') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Not issued</span>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Accepted by">
                        @if ($permit->isAccepted())
                            {{ $permit->acceptor?->name ?: '—' }}
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $permit->accepted_at?->format('j M Y H:i') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Not accepted</span>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Closed by">
                        @if ($permit->closed_at)
                            {{ $permit->closer?->name ?: '—' }}
                            <span class="block text-xs text-[var(--text-muted)]">
                                {{ $permit->closed_at->format('j M Y H:i') }}
                            </span>
                        @else
                            <span class="text-[var(--text-muted)]">Still open</span>
                        @endif
                    </x-register.fact>
                </dl>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Particulars</h3>
                </div>
                <dl class="divide-y divide-[var(--surface-border)]">
                    <x-register.fact label="Number" :value="$permit->number" mono/>
                    <x-register.fact label="Status">
                        @if ($permit->status)
                            <x-badge :tone="$permit->status->tone()">{{ $permit->status->label }}</x-badge>
                        @else
                            <x-badge tone="neutral">Awaiting issue</x-badge>
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Type" :value="$permit->permitType?->displayName()"/>
                    <x-register.fact label="Valid from" :value="$permit->valid_from?->format('j M Y H:i')"/>
                    <x-register.fact label="Valid to" :value="$permit->valid_to?->format('j M Y H:i')"/>
                    <x-register.fact label="Where" :value="$permit->location"/>
                    <x-register.fact label="Equipment" :value="$permit->equipment"/>
                    <x-register.fact label="Isolation" :value="$permit->isolation_reference" mono/>
                    <x-register.fact label="Gas test required"
                                     :value="$permit->gas_test_required ? 'Yes' : 'No'"/>
                    <x-register.fact label="Project" :value="$permit->project?->displayName()"/>
                    <x-register.fact label="Discipline" :value="$permit->discipline?->name"/>
                    <x-register.fact label="Department" :value="$permit->orgUnit?->name"/>
                    <x-register.fact label="Confidentiality" :value="$permit->confidentiality?->label()"/>
                    <x-register.fact label="Raised by" :value="$permit->creator?->name"/>
                </dl>
            </div>

            @if ($canDelete)
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Delete</h3>
                    </div>
                    <div class="space-y-3 p-5">
                        <p class="text-sm text-[var(--text-muted)]">
                            A permit that was ever issued is evidence of who authorised work, and
                            deleting one destroys that. Cancel it instead; delete only a request
                            raised in error.
                        </p>
                        <x-delete-form :action="route('permits.destroy', $permit)"
                                       label="Delete this permit"
                                       confirm="Delete it and its precautions?"/>
                    </div>
                </div>
            @endif
        </div>
    </div>

</x-layouts.app>
