@php
    // Whether the manager's half is shown as read-only text. The people writing it see it in their
    // own form; the subject sees it once the review is signed off, and not one status earlier —
    // half-written comments awaiting sign-off are not a decision anybody should read as one (§68).
    $showManagerAnswers = $canReview || $review->statusKey() === 'completed';
@endphp

<x-layouts.app :title="$review->number">
    <x-page-header :title="$review->subjectName().' — '.($review->cycle?->name ?? 'review')" :description="$review->number">
        <x-slot:actions>
            @if ($canEdit)<a href="{{ route('performance-reviews.edit', $review) }}" class="btn btn-secondary btn-sm">Edit</a>@endif
        </x-slot:actions>
    </x-page-header>
    @include('finance.errors')

    @if ($instance)
        <div class="mb-6"><x-workflow-progress :instance="$instance"/></div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($review->cycle?->instructions)
                <div class="card p-5">
                    <h2 class="card-title">How to fill this in</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $review->cycle->instructions }}</p>
                </div>
            @endif

            @if ($canSelfAssess)
                @include('performance-reviews.partials.self-form')
            @elseif ($canReview)
                @include('performance-reviews.partials.manager-form')
            @endif

            {{-- The read-only record. Shown to everybody who can open the review once there is
                 something to show, including the people who can still edit it — the form above is
                 what they change, this is what it looks like when it is done. --}}
            @if (! $canSelfAssess && ! $canReview)
                <div class="card p-5">
                    <h2 class="card-title">Answers</h2>
                    @if ($review->items->isEmpty())
                        <p class="mt-2 text-sm text-[var(--text-muted)]">This review has no lines on it.</p>
                    @else
                        <div class="mt-3 space-y-4">
                            @foreach ($review->items as $item)
                                <div class="rounded-lg border border-[var(--border)] p-4">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <p class="text-sm font-medium text-[var(--text-strong)]">{{ $item->label }}</p>
                                        <span class="text-xs capitalize text-[var(--text-muted)]">{{ $item->kind }}</span>
                                    </div>
                                    <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
                                        <div>
                                            <dt class="text-xs text-[var(--text-muted)]">Self</dt>
                                            <dd>{{ $item->self_score !== null ? rtrim(rtrim((string) $item->self_score, '0'), '.') : '—' }}
                                                @if ($item->self_comment)<span class="block text-xs text-[var(--text-muted)]">{{ $item->self_comment }}</span>@endif
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs text-[var(--text-muted)]">Manager</dt>
                                            <dd>
                                                @if ($showManagerAnswers)
                                                    {{ $item->manager_score !== null ? rtrim(rtrim((string) $item->manager_score, '0'), '.') : '—' }}
                                                    @if ($item->manager_comment)<span class="block text-xs text-[var(--text-muted)]">{{ $item->manager_comment }}</span>@endif
                                                @else
                                                    <span class="text-[var(--text-muted)]">Not yet signed off</span>
                                                @endif
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($review->self_comments && ! $canSelfAssess)
                <div class="card p-5">
                    <h2 class="card-title">{{ $review->subjectName() }} said</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $review->self_comments }}</p>
                </div>
            @endif

            @if ($showManagerAnswers && $review->manager_comments && ! $canReview)
                <div class="card p-5">
                    <h2 class="card-title">The manager's summary</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $review->manager_comments }}</p>
                </div>
            @endif

            @if ($showManagerAnswers && $review->development_plan && ! $canReview)
                <div class="card p-5">
                    <h2 class="card-title">Development plan</h2>
                    <p class="mt-2 whitespace-pre-line text-sm">{{ $review->development_plan }}</p>
                </div>
            @endif

            @if ($review->isAcknowledged())
                <div class="card p-5">
                    <h2 class="card-title">Acknowledged</h2>
                    <p class="mt-2 text-sm">
                        Seen by {{ $review->subjectName() }} on {{ $review->acknowledged_at?->format('j M Y H:i') }}.
                        <span class="block text-xs text-[var(--text-muted)]">An acknowledgement says they have read it, not that they agree with it.</span>
                    </p>
                    @if ($review->acknowledgement_comments)
                        <p class="mt-2 whitespace-pre-line text-sm">{{ $review->acknowledgement_comments }}</p>
                    @endif
                </div>
            @endif

            <x-timeline :entries="$timeline" title="History" empty="Nothing else has happened to this review."/>
        </div>

        <div class="space-y-6">
            <div class="card p-5">
                <dl class="space-y-4">
                    <x-register.fact label="Status" :value="$review->status?->label"/>
                    <x-register.fact label="Person" :value="$review->subjectName()"/>
                    <x-register.fact label="Position" :value="$review->employee?->position?->title"/>
                    <x-register.fact label="Cycle" :value="$review->cycle?->name"/>
                    <x-register.fact label="Period" :value="$review->cycle?->period_start?->format('j M Y').' – '.$review->cycle?->period_end?->format('j M Y')"/>
                    <x-register.fact label="Reviewer" :value="$review->reviewer?->name"/>
                    <x-register.fact label="Self-assessment sent" :value="$review->self_submitted_at?->format('j M Y H:i')"/>
                    @if ($showManagerAnswers)
                        <x-register.fact label="Overall rating" :value="$review->overall_rating !== null ? rtrim(rtrim((string) $review->overall_rating, '0'), '.') : null"/>
                    @endif
                </dl>
            </div>

            @if ($canRelease)
                <form method="POST" action="{{ route('performance-reviews.release', $review) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Send it out</h2>
                    <p class="text-xs text-[var(--text-muted)]">Tells {{ $review->subjectName() }} their self-assessment is open.</p>
                    <button class="btn btn-primary btn-sm w-full">Release to the employee</button>
                </form>
            @endif

            @if ($canAcknowledge)
                <form method="POST" action="{{ route('performance-reviews.acknowledge', $review) }}" class="card space-y-2 p-5">@csrf
                    <h2 class="card-title">Acknowledge</h2>
                    <p class="text-xs text-[var(--text-muted)]">Confirms you have read it. You may add a comment of your own, which is kept on the record.</p>
                    <x-textarea name="acknowledgement_comments" rows="3" placeholder="Optional — anything you want on file alongside this review."></x-textarea>
                    <button class="btn btn-primary btn-sm w-full">I have read this</button>
                </form>
            @endif

            @if ($canCancel)
                <form method="POST" action="{{ route('performance-reviews.cancel', $review) }}" class="card space-y-2 p-5">@csrf
                    <x-textarea name="reason" rows="2" placeholder="Why is this review not going ahead?"></x-textarea>
                    <button class="btn btn-ghost btn-sm w-full">Cancel this review</button>
                </form>
            @endif

            @can ('delete', $review)
                <div class="card p-5">
                    <x-delete-form :action="route('performance-reviews.destroy', $review)" label="Delete review" confirm="Delete this review and everything scored on it?"/>
                </div>
            @endcan
        </div>
    </div>
</x-layouts.app>
