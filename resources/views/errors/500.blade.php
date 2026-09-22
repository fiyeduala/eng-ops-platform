{{--
    The page that exists so that a production failure is not a stack trace.

    WITH `APP_DEBUG=false` THIS IS WHAT THE PUBLIC SEES, and everything Laravel would otherwise
    have shown here — file paths, the framework version, the query that failed, environment values,
    a fragment of the row being written — is disclosure. §84 in its plainest form: the person
    reading this may be the person who caused it on purpose.

    THE REFERENCE LINE IS THE POINT OF THE PAGE. Without it the support conversation is "it broke
    at some point today" against a log with thousands of lines in it. A timestamp the user can read
    off the screen narrows that to seconds and gives away nothing. The detail itself is in
    `storage/logs/laravel.log`, which is not reachable from a browser (§67).
--}}
@extends('errors.layout')

@section('code', 'Error 500')
@section('title', 'Something went wrong at our end')

@section('body')
    <p>
        This is a fault in the system, not something you did. It has been written to the server log
        with the full detail.
    </p>
    <p>
        Anything already saved is safe. Work is written in a single transaction, so an action that
        failed did not half-complete. If you were part way through a form, the details may still be
        on the page if you go back.
    </p>
@endsection

@section('actions')
    <a class="button primary" href="{{ url('/') }}">Back to the dashboard</a>
    <button class="button" type="button" onclick="history.back()">Go back</button>
@endsection

@section('reference')
    Quote this if you report it: <code>{{ now()->utc()->format('Y-m-d H:i:s') }} UTC</code>
@endsection
