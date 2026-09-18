{{--
    The subject's own half.

    Saving and sending are two buttons because a self-assessment is written over several sittings —
    a form that could only be submitted would be filled in one panic on the last afternoon. `send`
    is what tells the controller to move it on; the policy decides whether either is allowed at all.
--}}
<form method="POST" action="{{ route('performance-reviews.self', $review) }}" class="card p-5">
    @csrf
    <h2 class="card-title">Your self-assessment</h2>
    <p class="mt-1 text-xs text-[var(--text-muted)]">
        Scored on this cycle's scale: {{ $review->cycle?->scaleLabel() }}. Your manager sees this once you send it, not before.
    </p>

    <div class="mt-4 space-y-4">
        @foreach ($review->items as $item)
            <div class="rounded-lg border border-[var(--border)] p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium text-[var(--text-strong)]">{{ $item->label }}</p>
                        @if ($item->description)<p class="text-xs text-[var(--text-muted)]">{{ $item->description }}</p>@endif
                    </div>
                    <span class="text-xs capitalize text-[var(--text-muted)]">{{ $item->kind }}</span>
                </div>
                <div class="mt-3 grid gap-3 md:grid-cols-4">
                    <div>
                        <label class="label" for="self_score_{{ $item->id }}">Your score</label>
                        <input type="number" step="0.01" class="input" id="self_score_{{ $item->id }}"
                               name="items[{{ $item->id }}][self_score]"
                               value="{{ old('items.'.$item->id.'.self_score', $item->self_score) }}">
                    </div>
                    <div class="md:col-span-3">
                        <label class="label" for="self_comment_{{ $item->id }}">Evidence</label>
                        <input type="text" class="input" id="self_comment_{{ $item->id }}"
                               name="items[{{ $item->id }}][self_comment]" placeholder="What you did, not what you intended."
                               value="{{ old('items.'.$item->id.'.self_comment', $item->self_comment) }}">
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-4">
        <label class="label" for="self_comments">Anything else</label>
        <x-textarea name="self_comments" rows="4" placeholder="The year in your own words — what went well, what did not, what you want next.">{{ old('self_comments', $review->self_comments) }}</x-textarea>
    </div>

    <div class="mt-5 flex flex-wrap gap-2">
        <button class="btn btn-secondary">Save for now</button>
        <button name="send" value="1" class="btn btn-primary"
                onclick="return confirm('Send this to your manager? You will not be able to change your answers afterwards.')">Send to my manager</button>
    </div>
</form>
