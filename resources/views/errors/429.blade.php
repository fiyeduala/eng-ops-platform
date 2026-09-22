{{--
    Rate-limited. Almost always the login throttle (`LoginRequest::ensureIsNotRateLimited`).

    WHAT IT MUST NOT SAY: how many attempts are left, how long the window is, or whether the
    address being tried exists. All three are useful to somebody guessing, and none of them help
    the person who simply mistyped their own password twice.

    WHAT IT SHOULD SAY: that waiting works, and that a forgotten password has its own door. A
    locked-out user who does not know that will keep hitting this one.
--}}
@extends('errors.layout')

@section('code', 'Error 429')
@section('title', 'Too many attempts')

@section('body')
    <p>
        This came in faster than the system accepts. It is a protection against automated guessing,
        and it clears on its own.
    </p>
    <p>
        Wait a few minutes and try again. If you were signing in and cannot remember your password,
        use the password reset link rather than trying more combinations.
    </p>
@endsection

@section('actions')
    <a class="button primary" href="{{ url('/login') }}">Back to sign in</a>
@endsection
