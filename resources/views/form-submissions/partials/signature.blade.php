{{--
    A signature block on a filled-in form.

    WHAT IS SIGNED IS WHAT IS RECORDED. The hash a signature carries is taken over the answers as
    they stand in the database, not as they stand in the boxes on this screen — so the panel says to
    save first, and anything typed and not saved is not covered. A signature that attested to text
    nobody had written down would be worse than no signature.

    A SIGNATURE THAT NO LONGER MATCHES IS SAID SO, IN WORDS, ON THE PAGE. Two hex strings that
    disagree is not a thing anybody notices by eye, and a permit whose readings were edited after
    the supervisor signed is a permit nobody actually approved. On a required block the submission
    is refused until it is signed again (§28).

    THE SCOPE STATEMENT IS REPEATED HERE rather than left in the documentation, because a person
    pressing this button is entitled to know what they are and are not doing. These are internal
    approval signatures; docs/09 §5 carries the statement in full.

    Expects: $submission, $field, $compiler, $hash, $canSign, $actorId, $open
--}}
@php
    $signed = $compiler->signaturesOn($submission, $field);
    $mine = $signed->first(fn ($one) => (int) $one->user_id === (int) $actorId);
@endphp

<div class="rounded-lg border border-[var(--surface-border)]">
    <div class="border-b border-[var(--surface-border)] px-4 py-3">
        <p class="label mb-0">
            {{ $field->label }}
            @if ($field->is_required)
                <span class="text-[var(--text-muted)]">*</span>
            @endif
        </p>
        @if ($field->setting('designation_hint'))
            <p class="help">{{ $field->setting('designation_hint') }}</p>
        @endif
    </div>

    @if ($signed->isEmpty())
        <p class="px-4 py-3 text-sm text-[var(--text-muted)]">Not signed yet.</p>
    @else
        <ul class="divide-y divide-[var(--surface-border)]">
            @foreach ($signed as $signature)
                <li class="px-4 py-3">
                    <p class="text-sm font-medium text-[var(--text-strong)]">
                        {{ $signature->signatoryName() }}
                    </p>
                    {{-- The job title HELD ON THE DAY. A promotion next year must not rewrite who
                         signed this sheet as what they have since become. --}}
                    @if ($signature->signatoryTitle())
                        <p class="text-xs text-[var(--text-body)]">{{ $signature->signatoryTitle() }}</p>
                    @endif
                    <p class="text-xs text-[var(--text-muted)]">
                        Signed {{ $signature->signed_at?->format('j M Y, H:i') }}
                    </p>

                    @unless ($signature->matches($hash))
                        <p class="mt-1 text-xs font-medium text-[var(--tone-danger)]">
                            The answers have changed since this was signed, so it no longer covers
                            what this sheet says.
                        </p>
                    @endunless
                </li>
            @endforeach
        </ul>
    @endif

    @if ($canSign && $open)
        <div class="border-t border-[var(--surface-border)] px-4 py-3">
            @if ($mine)
                {{-- Withdrawing is a new event, not an edit: the original row stays exactly where
                     it is, and both facts are in the record. --}}
                <button type="submit" form="sign-withdraw-{{ $field->id }}" class="btn btn-secondary btn-sm">
                    Withdraw my signature
                </button>
                <p class="help mt-2">
                    The signature already recorded stays in the record — withdrawing adds to the
                    history rather than erasing it.
                </p>
            @else
                <button type="submit" form="sign-{{ $field->id }}" class="btn btn-primary btn-sm">
                    <x-icon name="pencil" class="h-3.5 w-3.5"/> Sign this block
                </button>
                <p class="help mt-2">
                    Save your answers first: a signature covers what has been recorded, not what is
                    still in the boxes.
                </p>
            @endif
        </div>
    @endif

    <p class="border-t border-[var(--surface-border)] px-4 py-2 text-xs text-[var(--text-muted)]">
        An internal electronic approval signature — evidence of who signed what and when, bound to a
        fingerprint of the answers. Not a qualified signature under eIDAS or ESIGN.
    </p>
</div>
