{{--
    Not found, and deliberately indistinguishable from not yours.

    THIS IS THE MOST SECURITY-SENSITIVE PAGE IN THE SET and it looks like the least. A record
    belonging to another company is removed by the tenant global scope before route binding ever
    resolves it, so it arrives here as an ordinary 404 (§75). Several controllers also choose 404
    over 403 on purpose — `BoardController::department()` among them — because a 403 on a specific
    id confirms that id exists, and somebody walking /projects/1, /projects/2, /projects/3 learns
    the size of your company from the difference between two responses (§84, §94).

    So this page must never be able to distinguish the three cases. No id, no name, no record type,
    no "the project you asked for". One page, identical every time.
--}}
@extends('errors.layout')

@section('code', 'Error 404')
@section('title', 'That page is not here')

@section('body')
    <p>
        The address is wrong, or what was there has been moved or removed. If you followed a link
        from an email or a bookmark, it may be pointing at something that no longer exists.
    </p>
    <p>
        Searching from the dashboard is usually faster than editing the address. Search only returns
        what you are allowed to see, so it will find the record if it is yours to find.
    </p>
@endsection
