{{--
    Planning a toolbox talk.

    THERE IS NO ATTENDANCE ON THIS FORM. Who was there is recorded on the talk's own page through
    `record`, at the point the talk has actually been given — and that act, not this one, is what
    moves the record to "held". Letting a planning form carry an attendance list would mean a sheet
    of names could be typed for a briefing that never happened (§59).

    THE PRESENTER IS TWO FIELDS AND THAT IS ON PURPOSE. Half the toolbox talks on a site are given
    by a subcontractor's supervisor with no account here; `presenter_name` is where they go, and the
    user picker beside it is for the other half.

    Expects: $talk, $projects, $units, $people, $confidentialities, $handouts
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The briefing</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required>
                    <x-input name="title" :value="old('title', $talk->title)" required/>
                </x-field>

                <x-field label="Topic" name="topic"
                         help="The subject in two or three words — what the sheet is filed under.">
                    <x-input name="topic" :value="old('topic', $talk->topic)"/>
                </x-field>

                <x-field label="What was said" name="content"
                         help="Enough that somebody who missed it could be briefed from this alone.">
                    <x-textarea name="content" rows="8">{{ old('content', $talk->content) }}</x-textarea>
                </x-field>

                <x-field label="Key points" name="key_points"
                         help="The two or three things the crew must walk away with.">
                    <x-textarea name="key_points" rows="4">{{ old('key_points', $talk->key_points) }}</x-textarea>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">When and where</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Where" name="location">
                        <x-input name="location" :value="old('location', $talk->location)"/>
                    </x-field>

                    <x-field label="How long (minutes)" name="duration_minutes">
                        <x-input type="number" name="duration_minutes" min="1" max="480"
                                 :value="old('duration_minutes', $talk->duration_minutes)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Presented by" name="presented_by"
                             help="Somebody with an account here.">
                        <x-select name="presented_by" :options="$people"
                                  :selected="old('presented_by', $talk->presented_by)"
                                  placeholder="Nobody named"/>
                    </x-field>

                    <x-field label="Or presenter's name" name="presenter_name"
                             help="For a supervisor or subcontractor with no account.">
                        <x-input name="presenter_name" :value="old('presenter_name', $talk->presenter_name)"/>
                    </x-field>
                </div>

                <x-field label="Handout" name="document_id"
                         help="The briefing sheet, if there is one. It lives in document control like every other file.">
                    <x-select name="document_id" :options="$handouts"
                              :selected="old('document_id', $talk->document_id)"
                              placeholder="No handout"/>
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
                              :selected="old('project_id', $talk->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $talk->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $talk->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="A briefing the crew cannot open has not been given.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $talk->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="toolbox_talk" :record="$talk" class="card mt-5"/>
