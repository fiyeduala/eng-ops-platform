{{--
    The manager's half, with the subject's own answers alongside — read-only, because the two scores
    are two authors and a form that let a manager tidy up somebody's self-assessment would make the
    pair of columns meaningless.

    "Send for sign-off" is a SECOND authorization, not a stronger version of saving: the controller
    re-authorizes `submitForSignOff` before it moves anything, and from there the workflow engine
    decides the review exactly as it decides a leave request.
--}}
<form method="POST" action="{{ route('performance-reviews.manager', $review) }}" class="card p-5">
    @csrf
    <h2 class="card-title">Your review of {{ $review->subjectName() }}</h2>
    <p class="mt-1 text-xs text-[var(--text-muted)]">
        Scored on this cycle's scale: {{ $review->cycle?->scaleLabel() }}. Saved answers are not visible to them until the review is signed off.
    </p>

    <div class="mt-4 space-y-4">
        @foreach ($review->items as $item)
            <div class="rounded-lg border border-[var(--border)] p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-[var(--text-strong)]">{{ $item->label }}</p>
                        @if ($item->description)<p class="text-xs text-[var(--text-muted)]">{{ $item->description }}</p>@endif
                    </div>
                    <span class="text-xs capitalize text-[var(--text-muted)]">{{ $item->kind }} · weight {{ rtrim(rtrim((string) $item->weight, '0'), '.') }}</span>
                </div>

                @if ($item->self_score !== null || $item->self_comment)
                    <div class="mt-3 rounded-md bg-[var(--surface-muted)] p-3 text-xs">
                        <span class="font-medium text-[var(--text-strong)]">They said:</span>
                        {{ $item->self_score !== null ? rtrim(rtrim((string) $item->self_score, '0'), '.') : 'no score' }}
                        @if ($item->self_comment) — {{ $item->self_comment }} @endif
                    </div>
                @endif

                <div class="mt-3 grid gap-3 md:grid-cols-4">
                    <div>
                        <label class="label" for="manager_score_{{ $item->id }}">Your score</label>
                        <input type="number" step="0.01" class="input" id="manager_score_{{ $item->id }}"
                               name="items[{{ $item->id }}][manager_score]"
                               value="{{ old('items.'.$item->id.'.manager_score', $item->manager_score) }}">
                    </div>
                    <div class="md:col-span-3">
                        <label class="label" for="manager_comment_{{ $item->id }}">Why</label>
                        <input type="text" class="input" id="manager_comment_{{ $item->id }}"
                               name="items[{{ $item->id }}][manager_comment]" placeholder="The reason for the score, in words they will read."
                               value="{{ old('items.'.$item->id.'.manager_comment', $item->manager_comment) }}">
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        {{-- Typed, not computed. The platform will not average somebody's year for them: the overall
             rating is a judgement a manager makes and signs their name to. --}}
        <x-field label="Overall rating" name="overall_rating" required
                 help="Required before this can go for sign-off. On the cycle's scale.">
            <x-input type="number" step="0.01" name="overall_rating" :value="old('overall_rating', $review->overall_rating)"/>
        </x-field>
    </div>

    <div class="mt-2 grid gap-4">
        <x-field label="Summary" name="manager_comments">
            <x-textarea name="manager_comments" rows="4" placeholder="The conversation in writing — what you will say to them face to face.">{{ old('manager_comments', $review->manager_comments) }}</x-textarea>
        </x-field>

        <x-field label="Development plan" name="development_plan"
                 help="What happens next year: the training, the exposure, the responsibility. Left empty, the review says nothing about their future.">
            <x-textarea name="development_plan" rows="4">{{ old('development_plan', $review->development_plan) }}</x-textarea>
        </x-field>
    </div>

    <div class="mt-5 flex flex-wrap gap-2">
        <button class="btn btn-secondary">Save for now</button>
        @if ($canSubmit)
            <button name="send" value="1" class="btn btn-primary"
                    onclick="return confirm('Send this for sign-off? It goes to your own line manager to approve.')">Send for sign-off</button>
        @endif
    </div>
</form>
