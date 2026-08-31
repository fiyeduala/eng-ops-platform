@props(['action', 'transitions', 'noun' => 'record'])

{{--
    The status move, shared by every engineering register.

    THE OPTIONS ARE THE ANSWER, NOT A MENU. `$transitions` arrives from StatusMachine::available(),
    which has already filtered the graph to the moves that exist FROM this status and that this
    person's permissions allow. A status missing from the list is a status this actor may not move
    to — and posting its id anyway fails in the controller, because the machine re-checks the whole
    thing server-side (§84). Nothing here is trusted.

    WHY THE COMMENT BOX APPEARS AND DISAPPEARS. Some transitions are configured to demand a reason
    ("rejected", "cancelled", "put on hold") and some are not. The rules ride in as a map so the
    field can mark itself required without a round trip — a convenience only. The requirement is
    enforced by StatusMachine, which refuses the transition if the comment is missing.
--}}

@php
    $rules = $transitions->mapWithKeys(fn ($t) => [
        (string) $t->to_status_id => (bool) $t->requires_comment,
    ])->all();
@endphp

<div class="border-t border-[var(--surface-border)] p-5">
    @if ($transitions->isEmpty())
        <p class="text-sm text-[var(--text-muted)]">
            There is nowhere for this {{ $noun }} to move from here — either it has reached a final
            status, or the moves that exist need a permission you do not hold.
        </p>
    @else
        <form method="POST" action="{{ $action }}" x-data="{ target: '', rules: @js($rules) }">
            @csrf
            <label for="status_id" class="label">Move this {{ $noun }} to</label>
            <select name="status_id" id="status_id" x-model="target" class="input" required>
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
                <textarea name="comment" id="comment" rows="2" class="input"
                          :required="rules[target]"
                          placeholder="Recorded on the timeline for whoever reads this later."></textarea>
            </div>

            @error('status_id')
                <p class="mt-2 text-sm text-rose-600">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn btn-primary btn-sm mt-3" x-bind:disabled="target === ''">
                <x-icon name="check" class="h-3.5 w-3.5"/> Apply
            </button>
        </form>
    @endif
</div>
