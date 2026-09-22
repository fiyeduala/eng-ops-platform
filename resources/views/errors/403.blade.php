{{--
    Refused, not missing. The record exists and this person may not have it.

    WHY THIS PAGE SAYS "ASK", NOT "REQUEST ACCESS". There is no self-service escalation in this
    system and there should not be: permission comes from a role, a role is assigned by somebody
    with `user.manage`, and both of those are decisions a person makes on the record of who needs
    what (§6, §10). A button here would either do nothing or quietly create a second grant path
    beside the one the whole access engine is built on.

    It is also already logged. `bootstrap/app.php` reports every AuthorizationException to the
    audit trail, because a run of these against sequential ids is what an IDOR probe looks like
    from the inside (§94). Nothing on this page needs to say so.
--}}
@extends('errors.layout')

@section('code', 'Error 403')
@section('title', 'You do not have access to this')

@section('body')
    <p>
        Your account does not include permission for this page or record. This is usually
        deliberate — access follows the role you have been given and the part of the organization
        you sit in.
    </p>
    <p>
        If you need it for your work, ask whoever administers this system to review your role. They
        can grant it without anyone touching the code.
    </p>
@endsection
