{{--
    Shared by create and edit.

    Absent by design: the number (numbering engine), the status (whitelist), the organization
    (session), and all four minutes columns. Minutes have their own page, their own route and their
    own permission — `meeting.record_minutes` is a different authority from `meeting.edit`, held by a
    different person in most organizations, and accepting a `minutes` field here would collapse the
    two (§84).

    The ORGANIZER is on this form, unlike a task's assignee, and the asymmetry is deliberate: being
    organizer confers reach, but over a meeting the actor is arranging and could equally have invited
    that person to, and an administrator booking on a director's behalf is the normal case. The
    policy is still asked about the candidate on the way in.

    Expects: $meeting, $projects, $units, $people, $confidentialities, $roles, $nextNumber
--}}

{{-- ---------------------------------------------------------------- identity --}}
<div class="card-header">
    <h3 class="card-title">The meeting</h3>
    @if ($nextNumber)
        <span class="font-mono text-xs text-[var(--text-muted)]">Next number: {{ $nextNumber }}</span>
    @endif
</div>

<div class="grid gap-5 p-5">
    <x-field label="Title" name="title" required
             help="What the meeting is for. “Weekly” is a frequency, not a subject.">
        <x-input name="title" :value="$meeting->title" required autofocus/>
    </x-field>

    <x-field label="Agenda" name="agenda"
             help="Circulated with the invitation. Never quoted in the notification itself.">
        <x-textarea name="agenda" :value="$meeting->agenda" rows="6"/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- when --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">When and where</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Starts" name="starts_at" required>
        <x-input name="starts_at" type="datetime-local"
                 :value="$meeting->starts_at?->format('Y-m-d\TH:i')" required/>
    </x-field>

    <x-field label="Ends" name="ends_at" help="Optional, but it is what puts a duration on the calendar.">
        <x-input name="ends_at" type="datetime-local"
                 :value="$meeting->ends_at?->format('Y-m-d\TH:i')"/>
    </x-field>

    <x-field label="Location" name="location" help="A room, a site, an address — wherever people physically go.">
        <x-input name="location" :value="$meeting->location" placeholder="e.g. Site office, Meeting Room 2"/>
    </x-field>

    <x-field label="Meeting link" name="meeting_url" help="For anybody joining remotely.">
        <x-input name="meeting_url" type="url" :value="$meeting->meeting_url" placeholder="https://…"/>
    </x-field>
</div>

@if ($meeting->exists)
    <p class="px-5 pb-5 text-xs text-[var(--text-muted)]">
        Changing the time, the place or the link notifies everybody already invited, and tells them what
        it was before. Move a meeting rather than cancelling and re-creating it, so that history stays
        attached to the same record.
    </p>
@endif

{{-- ---------------------------------------------------------------- placement --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Where it belongs</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Project" name="project_id"
             help="Optional. Filing it against a project also puts it on that project's timeline, and actions raised from it inherit the project.">
        <x-select name="project_id" :options="$projects" :selected="$meeting->project_id" placeholder="No project"/>
    </x-field>

    <x-field label="Department" name="org_unit_id"
             help="Which part of the company is holding it. People who reach that unit reach the meeting.">
        <x-select name="org_unit_id" :options="$units" :selected="$meeting->org_unit_id" placeholder="Not assigned"/>
    </x-field>

    <x-field label="Organizer" name="organizer_id"
             help="Whoever is actually calling it. Naming somebody here gives that account sight of the meeting.">
        @if ($people === [])
            <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                You cannot see login accounts, so this stays with you.
            </p>
        @else
            <x-select name="organizer_id" :options="$people" :selected="$meeting->organizer_id"
                      placeholder="Me"/>
        @endif
    </x-field>

    <x-field label="Sensitivity" name="confidentiality" required
             help="Above “Internal”, being invited is not enough on its own — the meeting also has to be reachable through its unit, its project, or an explicit grant.">
        <x-select name="confidentiality" :options="$confidentialities"
                  :selected="$meeting->confidentiality?->value" required/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- who --}}
@if (! $meeting->exists)
    <div class="card-header border-t border-[var(--surface-border)]">
        <h3 class="card-title">Who is expected</h3>
    </div>

    <div class="grid gap-5 p-5 sm:grid-cols-2">
        <x-field label="Invite" name="participants" class="sm:col-span-2"
                 help="Optional now — you can invite people from the meeting page afterwards.">
            @if ($people === [])
                <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                    You cannot see login accounts, so you cannot invite anybody yet. Book it and add people
                    from the meeting page.
                </p>
            @else
                <select name="participants[]" id="participants" multiple size="8" class="select h-auto">
                    @foreach ($people as $id => $name)
                        <option value="{{ $id }}" @selected(in_array((string) $id, array_map('strval', (array) old('participants', [])), true))>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </x-field>

        <x-field label="Invited as" name="participant_role"
                 help="Applies to everybody in this first batch. Roles are seating, not permissions — a chair holds no authority the access engine recognises.">
            <x-select name="participant_role" :options="$roles" :selected="old('participant_role', 'participant')"/>
        </x-field>
    </div>

    <p class="px-5 pb-5 text-xs text-[var(--text-muted)]">
        Inviting somebody is a grant: the people named here can open this meeting whether or not they
        could otherwise reach it. The list only offers accounts your own access reaches, and every name
        is checked again when you save.
    </p>
@endif


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="meeting" :record="$meeting" class="card mt-5"/>
