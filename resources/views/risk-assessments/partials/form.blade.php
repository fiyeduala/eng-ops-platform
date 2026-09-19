{{--
    The cover sheet of a risk assessment. The hazards themselves are a separate screen.

    THAT SPLIT IS DELIBERATE. A hazard table is twenty columns wide and gets edited a dozen times
    while the job is being planned; the cover sheet — what job, where, who wrote it, how long it
    lasts — is written once. Putting both on one form produces a page nobody can fill in on a tablet
    and a save that rewrites rows the writer never touched.

    VALID TO IS THE FIELD THE WHOLE REGISTER TURNS ON. Leave it blank and the assessment never
    lapses, which is occasionally right and usually a mistake — the review frequency beside it is the
    honest way to say "this needs looking at again" without pretending to know the date.

    THERE IS NO APPROVAL FIELD HERE. Approving is a separate act with its own permission, on the
    assessment's own page, and it is refused to the person who wrote the sheet (§84).

    Expects: $assessment, $projects, $units, $people, $confidentialities, $types
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The job being assessed</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required>
                    <x-input name="title" :value="old('title', $assessment->title)" required/>
                </x-field>

                <x-field label="Scope" name="description"
                         help="What this sheet covers, and just as usefully what it does not.">
                    <x-textarea name="description" rows="4">{{ old('description', $assessment->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Activity" name="activity"
                             help="The task in the words the crew would use.">
                        <x-input name="activity" :value="old('activity', $assessment->activity)"/>
                    </x-field>

                    <x-field label="Where" name="location">
                        <x-input name="location" :value="old('location', $assessment->location)"/>
                    </x-field>

                    <x-field label="Type" name="assessment_type">
                        <x-select name="assessment_type" :options="$types"
                                  :selected="old('assessment_type', $assessment->assessment_type)"
                                  placeholder="Choose one"/>
                    </x-field>

                    <x-field label="Assessed by" name="assessed_by"
                             help="The competent person who wrote it. They cannot also approve it.">
                        <x-select name="assessed_by" :options="$people"
                                  :selected="old('assessed_by', $assessment->assessed_by)"
                                  placeholder="Not stated"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">How long it stands</h3>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-3">
                <x-field label="Assessed on" name="assessed_on">
                    <x-input type="date" name="assessed_on"
                             :value="old('assessed_on', $assessment->assessed_on?->format('Y-m-d'))"/>
                </x-field>

                <x-field label="Valid from" name="valid_from">
                    <x-input type="date" name="valid_from"
                             :value="old('valid_from', $assessment->valid_from?->format('Y-m-d'))"/>
                </x-field>

                <x-field label="Valid to" name="valid_to"
                         help="Blank means it never lapses. Rarely true.">
                    <x-input type="date" name="valid_to"
                             :value="old('valid_to', $assessment->valid_to?->format('Y-m-d'))"/>
                </x-field>

                <x-field label="Review every (months)" name="review_frequency_months">
                    <x-input type="number" name="review_frequency_months" min="1" max="60"
                             :value="old('review_frequency_months', $assessment->review_frequency_months)"/>
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it sits</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $assessment->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $assessment->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $assessment->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="An assessment the crew cannot open is not a control.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $assessment->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="hse_risk" :record="$assessment" class="card mt-5"/>
