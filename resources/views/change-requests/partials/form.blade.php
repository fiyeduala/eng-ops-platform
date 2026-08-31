{{--
    Raising or amending a change request.

    THE DECISION IS NOT ON THIS FORM. Disposition, decision notes and the decider are written by
    `decide()`, behind `change_request.approve`, and the policy there refuses to let the author
    decide their own request. A disposition field here would defeat the separation of duties that
    is the entire reason this record exists separately from a task (§84).

    THE IMPACT FIGURES ARE ASKED FOR EVEN THOUGH THEY ARE OPTIONAL. On the day a change is first
    noticed nobody knows what it costs, and a required field would be filled with a made-up number
    — which is worse than a blank. They can still be set at the moment of decision, because that is
    frequently when the estimator prices it, and after that they are frozen.

    COST MAY BE NEGATIVE. A change that saves money is still a change, and forcing a saving to be
    typed as a cost is how a project reports a change budget it never spent.

    Expects: $changeRequest, $projects, $units, $people, $origins, $confidentialities
--}}
<div class="space-y-6">

    {{-- ---------------------------------------------------------------- the change --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">The change</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Title" name="title" required
                     help="One line, as it will read on a decision list months from now.">
                <x-input name="title" :value="$changeRequest->title" required maxlength="300"
                         placeholder="e.g. Relocation of substation to accommodate revised site levels"/>
            </x-field>

            <x-field label="Description" name="description" required
                     help="What is actually changing, in enough detail that somebody who was not in the room can decide on it. This is the field the decision is made against.">
                <x-textarea name="description" rows="7" required>{{ old('description', $changeRequest->description) }}</x-textarea>
            </x-field>

            <x-field label="Reason" name="reason"
                     help="Why the change arose. This is what a claim is argued from — a change with no recorded cause is a change somebody else will say was yours.">
                <x-textarea name="reason" rows="4">{{ old('reason', $changeRequest->reason) }}</x-textarea>
            </x-field>

            <x-field label="Origin" name="origin" required
                     help="Where the change came from. This is what separates a client instruction you can bill from design development you cannot.">
                <x-select name="origin" :options="$origins"
                          :selected="old('origin', $changeRequest->origin)" required/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- impact --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Impact</h3>
        </div>

        <div class="space-y-5 p-5">
            <p class="text-sm text-[var(--text-muted)]">
                Leave these blank if the change has not been priced yet. Whoever decides it can set
                them at that moment — after the decision they are fixed, because a cost that could
                be edited afterwards is not evidence of anything.
            </p>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Cost impact" name="cost_impact"
                         help="In your company's own currency. Negative for a saving.">
                    <x-input type="number" name="cost_impact" step="0.01"
                             :value="old('cost_impact', $changeRequest->cost_impact)"
                             placeholder="0.00"/>
                </x-field>

                <x-field label="Schedule impact (days)" name="schedule_impact_days"
                         help="Positive delays the programme, negative pulls it in.">
                    <x-input type="number" name="schedule_impact_days" step="1"
                             :value="old('schedule_impact_days', $changeRequest->schedule_impact_days)"
                             placeholder="0"/>
                </x-field>
            </div>

            <x-field label="Impact notes" name="impact_notes"
                     help="How the figures were arrived at, and what they assume.">
                <x-textarea name="impact_notes" rows="3">{{ old('impact_notes', $changeRequest->impact_notes) }}</x-textarea>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- where and who --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Where it sits</h3>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $changeRequest->project_id)"
                              placeholder="Not against a project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $changeRequest->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Department" name="org_unit_id"
                         help="The unit answerable for the change. This is what decides who else in the company can see it.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $changeRequest->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $changeRequest->confidentiality?->value)"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                <x-field label="Raised by" name="raised_by">
                    <x-select name="raised_by" :options="$people"
                              :selected="old('raised_by', $changeRequest->raised_by)"
                              placeholder="Nobody"/>
                </x-field>

                <x-field label="Raised on" name="raised_on">
                    <x-input type="date" name="raised_on"
                             :value="old('raised_on', $changeRequest->raised_on?->toDateString())"/>
                </x-field>

                <x-field label="Decision needed by" name="required_by"
                         help="The date after which the change costs more to decide than to make.">
                    <x-input type="date" name="required_by"
                             :value="old('required_by', $changeRequest->required_by?->toDateString())"/>
                </x-field>
            </div>
        </div>
    </div>
</div>
