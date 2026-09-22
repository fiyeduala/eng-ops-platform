{{--
    `php artisan down`, which on this platform means a deployment is in progress.

    docs/12 puts the maintenance window around `migrate --force` specifically, because a schema
    change against live requests is the one failure that can leave data half-written. So this page
    is expected, brief, and not a fault — and it needs to read that way, or the first person to see
    it raises a ticket about an outage that was a scheduled two minutes.

    NO DATABASE ACCESS HERE AT ALL, for the obvious reason: during a migration, the schema this
    page would be querying is the thing that is moving.
--}}
@extends('errors.layout')

@section('code', 'Error 503')
@section('title', 'Down for a few minutes')

@section('body')
    <p>
        The system is being updated. This is planned, it is usually over in a couple of minutes, and
        nothing is lost while it runs.
    </p>
    <p>
        Refresh in a moment. If it is still showing this after fifteen minutes, tell whoever
        administers the system — an update that has stalled needs a person, not more waiting.
    </p>
@endsection

@section('actions')
    <a class="button primary" href="{{ url()->current() }}">Try again</a>
@endsection
