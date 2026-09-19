{{--
    The forms behind every signature button on the page.

    THEY ARE HERE AND NOT BESIDE THEIR BUTTONS BECAUSE A FORM CANNOT BE NESTED IN A FORM. The
    fill-in screen is one large form around every answer on the sheet, and a signing form inside it
    would be invalid HTML that browsers resolve by quietly discarding one of them. So each button
    names its form by id — the same mechanism the Save and Submit buttons already use — and the
    forms themselves sit outside everything.

    ONE PER BLOCK, AND ONE MORE FOR WITHDRAWING IT. Two endpoints rather than a hidden field saying
    which, because signing and withdrawing are different acts and a hidden field deciding between
    them is a thing the browser posts (§84).

    Expects: $submission, $blocks, $canSign, $open
--}}
@if ($canSign && $open)
    @foreach ($blocks as $block)
        <form id="sign-{{ $block->id }}" method="POST" class="hidden"
              action="{{ route('form-submissions.sign', [$submission, $block]) }}">
            @csrf
        </form>

        <form id="sign-withdraw-{{ $block->id }}" method="POST" class="hidden"
              action="{{ route('form-submissions.sign.withdraw', [$submission, $block]) }}">
            @csrf
            @method('DELETE')
        </form>
    @endforeach
@endif
