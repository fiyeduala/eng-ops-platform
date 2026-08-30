{{--
    Shared by create and edit.

    Absent by design, and each for a different reason:

      - the NUMBER, issued by the numbering engine at save (§64)
      - the STATUS, which only moves through the configured whitelist, from the memo page (§63)
      - `issued_at` / `issued_by`, which ARE the draft curtain — a field of either name here would
        be a way to put a memo in front of its distribution list without holding `memo.issue` (§84)
      - the RECIPIENTS on edit, because addressing is a grant with its own route and its own policy

    An issued memo never reaches this form at all: MemoPolicy refuses `update` once it is out. A
    memo is a statement of what was communicated and when, and correcting one in place would rewrite
    that under everybody who already acted on it (§59). Withdraw and re-issue instead.

    Expects: $memo, $projects, $units, $people, $confidentialities, $nextNumber
--}}

{{-- ---------------------------------------------------------------- identity --}}
<div class="card-header">
    <h3 class="card-title">The memo</h3>
    @if ($nextNumber)
        <span class="font-mono text-xs text-[var(--text-muted)]">Next number: {{ $nextNumber }}</span>
    @endif
</div>

<div class="grid gap-5 p-5">
    <x-field label="Subject" name="subject" required
             help="What this is about, in the words somebody scanning their inbox would recognise.">
        <x-input name="subject" :value="$memo->subject" required autofocus/>
    </x-field>

    <x-field label="Body" name="body" required
             help="The memo itself. It is never quoted in the notification that announces it and never searched — recipients read it here.">
        <x-textarea name="body" :value="$memo->body" rows="10" required/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- placement --}}
<div class="card-header border-t border-[var(--surface-border)]">
    <h3 class="card-title">Where it belongs</h3>
</div>

<div class="grid gap-5 p-5 sm:grid-cols-2">
    <x-field label="Project" name="project_id"
             help="Optional. Filing it against a project also puts it on that project's timeline.">
        <x-select name="project_id" :options="$projects" :selected="$memo->project_id" placeholder="No project"/>
    </x-field>

    <x-field label="Department" name="org_unit_id"
             help="Which part of the company this memo comes out of. People who reach that unit reach the memo.">
        <x-select name="org_unit_id" :options="$units" :selected="$memo->org_unit_id" placeholder="Not assigned"/>
    </x-field>

    <x-field label="Sensitivity" name="confidentiality" required class="sm:col-span-2"
             help="Above “Internal”, being on the distribution list is not enough on its own — so issuing a restricted memo writes an explicit grant for each recipient.">
        <x-select name="confidentiality" :options="$confidentialities"
                  :selected="$memo->confidentiality?->value" required/>
    </x-field>
</div>

{{-- ---------------------------------------------------------------- distribution --}}
@if (! $memo->exists)
    <div class="card-header border-t border-[var(--surface-border)]">
        <h3 class="card-title">Who it goes to</h3>
    </div>

    <div class="p-5">
        <x-field label="Distribution list" name="recipients"
                 help="Optional now — you can add people from the memo page before you issue it. Nothing is sent until you do.">
            @if ($people === [])
                <p class="input flex items-center bg-[var(--surface-muted)] text-xs text-[var(--text-muted)]">
                    You cannot see login accounts, so you cannot address this yet. Draft it and add the
                    distribution from the memo page once somebody grants you sight of the directory.
                </p>
            @else
                <select name="recipients[]" id="recipients" multiple size="8" class="select h-auto">
                    @foreach ($people as $id => $name)
                        <option value="{{ $id }}" @selected(in_array((string) $id, array_map('strval', (array) old('recipients', [])), true))>{{ $name }}</option>
                    @endforeach
                </select>
            @endif
        </x-field>

        <p class="mt-3 text-xs text-[var(--text-muted)]">
            Addressing somebody is a grant, not a note: the people named here can open this memo once
            it is issued, whether or not they could otherwise reach it. The list only offers people
            your own access already reaches, and every name is checked again when you save.
        </p>
    </div>
@endif
