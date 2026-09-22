{{--
    The one error page most people will actually meet, and the one most likely to be read as a bug.

    419 is a CSRF token that no longer matches — nearly always a form left open past the session
    lifetime, sometimes a second tab that signed out underneath the first. The user's reaction is
    "it lost my work", and on a long form — a risk assessment, a payroll run, a purchase order —
    they are right to be annoyed. So the page says plainly what to do about the text still sitting
    on the page behind it. "Page expired" and nothing else is how that text gets lost.
--}}
@extends('errors.layout')

@section('code', 'Error 419')
@section('title', 'This page sat open too long')

@section('body')
    <p>
        For security, the form you just submitted is no longer valid — either the session timed out
        while the page was open, or you signed out in another tab.
    </p>
    <p><strong>Before you go anywhere:</strong></p>
    <ul>
        <li>Go back in your browser. What you typed is usually still on the page.</li>
        <li>Copy anything long into a note.</li>
        <li>Sign in again, then paste it into a fresh form and submit.</li>
    </ul>
@endsection

@section('actions')
    <a class="button primary" href="{{ url('/login') }}">Sign in again</a>
    <button class="button" type="button" onclick="history.back()">Go back to the form</button>
@endsection
