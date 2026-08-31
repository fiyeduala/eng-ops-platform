{{--
    A query being raised.

    THE ANSWER IS NOT ON THIS FORM. Answering is a different act, behind a different permission, on
    the query's own page — because "here is my question and here is the answer I have written for
    you" is not a question, and a register that allowed it could not be used to show that a position
    was actually put to the other side and actually came back.

    DIRECTION IS THE FIELD PEOPLE GET WRONG, so it is second on the form with its consequence spelled
    out. Choose it wrongly and the query lands in the wrong half of the outstanding report, which is
    the one report this register exists to produce.

    THE PARTY IS FREE TEXT, deliberately. Most queries go to somebody this platform has never heard
    of — a consultant, a regulator, one named engineer at a supplier. A field that could only name
    existing records is how a register fills up with invented client entries.

    Expects: $rfi, $projects, $units, $people, $kinds, $directions, $confidentialities
--}}
<div class="space-y-6">

    {{-- ---------------------------------------------------------------- the question --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">The question</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Subject" name="subject" required
                     help="A one-line summary. This is what appears on the register and in search — the question itself is deliberately not searchable.">
                <x-input name="subject" :value="$rfi->subject" required maxlength="250"
                         placeholder="e.g. Clarification of rebar cover to external columns"/>
            </x-field>

            <x-field label="Question" name="question" required
                     help="Everything the person answering needs: the drawing, the clause, the assumption you are making if no answer arrives.">
                <x-textarea name="question" rows="6" required>{{ old('question', $rfi->question) }}</x-textarea>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Kind" name="kind" required
                         help="Fixed once saved — it chooses the number series, and a query that changed kind would carry the wrong reference for life.">
                    <x-select name="kind" :options="$kinds" :selected="old('kind', $rfi->kind?->value)" required/>
                </x-field>

                <x-field label="Direction" name="direction" required
                         help="Who owes the answer. Get this wrong and the query lands in the wrong half of the outstanding report.">
                    <x-select name="direction" :options="$directions"
                              :selected="old('direction', $rfi->direction?->value)" required/>
                </x-field>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- who and when --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Who and when</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Party" name="party_name"
                     help="The organization or person on the other side of this query.">
                <x-input name="party_name" :value="$rfi->party_name" maxlength="180"
                         placeholder="e.g. Meridian Consulting Engineers"/>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $rfi->project_id)"
                              placeholder="Not against a project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $rfi->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <x-field label="Raised by" name="raised_by"
                         help="The engineer whose question it is — not necessarily whoever typed it.">
                    <x-select name="raised_by" :options="$people" :selected="old('raised_by', $rfi->raised_by)"
                              placeholder="Nobody"/>
                </x-field>

                <x-field label="Raised on" name="raised_on">
                    <x-input type="date" name="raised_on" :value="old('raised_on', $rfi->raised_on?->toDateString())"/>
                </x-field>

                <x-field label="Answer required by" name="required_by"
                         help="Suggested from your company's standard response time.">
                    <x-input type="date" name="required_by"
                             :value="old('required_by', $rfi->required_by?->toDateString())"/>
                </x-field>
            </div>

            <x-field label="Confidentiality" name="confidentiality">
                <x-select name="confidentiality" :options="$confidentialities"
                          :selected="old('confidentiality', $rfi->confidentiality?->value)"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- impact --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Anticipated impact</h3>
        </div>

        <div class="space-y-5 p-5">
            <p class="text-sm text-[var(--text-muted)]">
                Flagging impact when a query is raised is a warning, not a claim. What it does is put
                the query in front of the commercial team early — the answer frequently turns into a
                change request, and a query that flagged nothing until the answer arrived gives them
                no notice at all.
            </p>

            <label class="flex items-start gap-3">
                <input type="hidden" name="cost_impact" value="0">
                <input type="checkbox" name="cost_impact" value="1" class="mt-0.5"
                       @checked(old('cost_impact', $rfi->cost_impact))>
                <span class="text-sm text-[var(--text-body)]">The answer is likely to affect cost</span>
            </label>

            <label class="flex items-start gap-3">
                <input type="hidden" name="schedule_impact" value="0">
                <input type="checkbox" name="schedule_impact" value="1" class="mt-0.5"
                       @checked(old('schedule_impact', $rfi->schedule_impact))>
                <span class="text-sm text-[var(--text-body)]">The answer is likely to affect the programme</span>
            </label>

            <x-field label="Impact notes" name="impact_notes">
                <x-textarea name="impact_notes" rows="3">{{ old('impact_notes', $rfi->impact_notes) }}</x-textarea>
            </x-field>
        </div>
    </div>
</div>
