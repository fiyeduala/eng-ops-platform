{{--
    A deliverable's promise. Everything on this form is something a person can commit to before any
    work has started, which is why none of it touches progress, forecast or issue dates — those are
    reported later, by the person doing the work, through their own routes. A form that let somebody
    type "80% complete" on a line they were creating would be a form for inventing progress.

    THE PROJECT IS REQUIRED, unlike almost every other register here. A deliverable is a promise made
    inside a scope of work; one floating free of a project cannot be reported on, chased, or invoiced,
    and every register that allowed it ends up with a hundred orphans nobody owns.

    WEIGHT IS THE SHARE OF SCOPE this line represents, and it is what makes "the discipline is 40%
    complete" mean anything. Left at 1, every line counts the same and the number is a straight
    average — wrong in theory, and much better than nothing.

    Expects: $deliverable, $projects, $units, $people, $types, $confidentialities, $nextNumber
--}}
<div class="space-y-6">

    {{-- ---------------------------------------------------------------- what it is --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">What is promised</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Title" name="title" required
                     help="How this will be referred to on a progress report. Specific beats short.">
                <x-input name="title" :value="$deliverable->title" required maxlength="250"
                         placeholder="e.g. Ground floor slab reinforcement detail"/>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Type" name="type"
                         help="Your company's own list. Change it under Settings › Engineering registers.">
                    <x-select name="type" :options="$types" :selected="old('type', $deliverable->type)"
                              placeholder="Unclassified"/>
                </x-field>

                <x-field label="Revision" name="revision"
                         help="The revision being promised, if this is a reissue.">
                    <x-input name="revision" :value="$deliverable->revision" maxlength="16" placeholder="e.g. A"/>
                </x-field>
            </div>

            <x-field label="Description" name="description"
                     help="Scope, assumptions, what is excluded — whatever the person picking this up in three months needs.">
                <x-textarea name="description" rows="4">{{ old('description', $deliverable->description) }}</x-textarea>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- where it sits --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Where it sits</h3>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id" required
                         help="A deliverable belongs to a scope of work. Only projects you have access to are listed.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $deliverable->project_id)"
                              placeholder="Choose a project" required/>
                </x-field>

                <x-field label="Discipline" name="discipline_id"
                         help="The team that owns the work — civil, process, electrical, whichever your org chart calls them.">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $deliverable->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Responsible" name="responsible_id"
                         help="Who is producing it. They will see the deliverable whether or not their scope would otherwise reach it.">
                    <x-select name="responsible_id" :options="$people"
                              :selected="old('responsible_id', $deliverable->responsible_id)"
                              placeholder="Nobody yet"/>
                </x-field>

                <x-field label="Department" name="org_unit_id"
                         help="Leave blank to file it under the discipline. Set it only when the owning department differs.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $deliverable->org_unit_id)"
                              placeholder="Same as discipline"/>
                </x-field>
            </div>

            <x-field label="Confidentiality" name="confidentiality"
                     help="Restricted lines are hidden from people whose access does not extend to confidential records, even inside the project.">
                <x-select name="confidentiality" :options="$confidentialities"
                          :selected="old('confidentiality', $deliverable->confidentiality?->value)"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- the dates --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">The dates</h3>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Planned start" name="planned_start_on">
                    <x-input type="date" name="planned_start_on"
                             :value="old('planned_start_on', $deliverable->planned_start_on?->toDateString())"/>
                </x-field>

                <x-field label="Promised for" name="planned_issue_on"
                         help="The date this was committed to. It is what lateness is measured against, and it is deliberately never changed by reporting progress.">
                    <x-input type="date" name="planned_issue_on"
                             :value="old('planned_issue_on', $deliverable->planned_issue_on?->toDateString())"/>
                </x-field>
            </div>

            <x-field label="Weight" name="weight"
                     help="This line's share of the scope, used when rolling progress up to a discipline. Leave at 1 if you do not weight deliverables.">
                <x-input type="number" name="weight" step="0.01" min="0" max="9999.99"
                         :value="old('weight', $deliverable->weight ?? 1)" class="sm:max-w-[10rem]"/>
            </x-field>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="deliverable" :record="$deliverable" class="card mt-5"/>
