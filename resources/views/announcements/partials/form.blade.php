{{--
    Shared by create and edit.

    There is no sensitivity field on this form, and that is a schema decision rather than an
    oversight: an announcement is a broadcast by definition, so the column does not exist. The only
    narrowing available is the addressing pair below, and it decides who the notice is ROUTED to —
    never who is permitted to read it.

    Also absent: the number (numbering engine), the status (whitelist), and `published_at` /
    `published_by`, which are the draft curtain. A field of either name here would put a notice in
    front of the whole company without `announcement.publish` (§84).

    Unlike a memo, a published notice stays editable. A wrong date on a site-closure notice should be
    corrected in place rather than superseded by a second notice nobody reads. Re-AIMING a live
    notice is different — that changes who is being told, so the controller asks for `publish`.

    Expects: $announcement, $projects, $units, $urgencies, $nextNumber
--}}

{{-- ---------------------------------------------------------------- identity --}}
<div class="card-header">
    <h3 class="card-title">The notice</h3>
    @if ($nextNumber)
        <span class="font-mono text-xs text-[var(--text-muted)]">Next number: {{ $nextNumber }}</span>
    @endif
</div>

<div class="grid gap-5 p-5">
    <x-field label="Title" name="title" required
             help="The line people read on the dashboard. Say the thing itself, not the category of thing.">
        <x-input name="title" :value="$announcement->title" required autofocus/>
    </x-field>

    <x-field label="Notice" name="body" required>
        <x-textarea name="body" :value="$announcement->body" rows="8" required/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- audience --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Who it is for</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Project" name="project_id"
             help="Aimed at the people currently on this project.">
        <x-select name="project_id" :options="$projects" :selected="$announcement->project_id" placeholder="Not project-specific"/>
    </x-field>

    <x-field label="Department" name="org_unit_id"
             help="Aimed at this unit and everything under it — a notice posted to Engineering reaches a team three levels inside it.">
        <x-select name="org_unit_id" :options="$units" :selected="$announcement->org_unit_id" placeholder="Not department-specific"/>
    </x-field>

    <div class="sm:col-span-2 rounded-md bg-[var(--surface-muted)] p-3 text-xs text-[var(--text-muted)]">
        Leave both blank and the notice goes to everybody. Setting both aims it at the people who are
        on that project <em>and</em> in that department — the narrowest option there is. Either way this
        is routing, not secrecy: anybody who consults the board can read it.
    </div>
</div>

{{-- ---------------------------------------------------------------- handling --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">How it is handled</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Urgency" name="urgency" required
             help="“Urgent” overrides a recipient's quiet hours, so it is for site closures and safety stand-downs — not for deadlines.">
        <x-select name="urgency" :options="$urgencies" :selected="$announcement->urgency" required/>
    </x-field>

    <x-field label="Show until" name="expires_on"
             help="Optional. After this date the notice drops off the board but stays in the archive.">
        <x-input name="expires_on" type="date" :value="$announcement->expires_on?->format('Y-m-d')"/>
    </x-field>

    <div class="sm:col-span-2">
        <label class="flex items-start gap-3">
            <input type="hidden" name="is_pinned" value="0">
            <input type="checkbox" name="is_pinned" value="1"
                   @checked(old('is_pinned', $announcement->is_pinned))
                   class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
            <span>
                <span class="text-sm font-medium text-[var(--text-strong)]">Pin to the top of the board</span>
                <span class="help">Pinned notices sort above everything else. Pin sparingly — a board where
                everything is pinned is a board where nothing is.</span>
            </span>
        </label>
    </div>
</div>
