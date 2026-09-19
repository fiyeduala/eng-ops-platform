{{--
    Planning an inspection. The checklist is a separate screen and the findings are a third.

    THIS FORM SCHEDULES; IT DOES NOT RECORD. There is no result field anywhere on it, and that is
    the point of the module: the person who plans the walk and the person who writes down what they
    saw are held apart, so a poor result cannot be tidied up by editing the plan afterwards (§59).

    ACCOMPANIED BY IS FREE TEXT ON PURPOSE. A site walk is very often done with a subcontractor's
    foreman or a client representative, and neither has an account here. Forcing that name into a
    user picker would mean either inventing accounts for visitors or leaving the field blank.

    Expects: $inspection, $projects, $units, $people, $confidentialities, $types
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The walk</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required>
                    <x-input name="title" :value="old('title', $inspection->title)" required/>
                </x-field>

                <x-field label="Scope" name="description"
                         help="What is being inspected, and anything the inspector should be told before they set off.">
                    <x-textarea name="description" rows="4">{{ old('description', $inspection->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Type" name="inspection_type">
                        <x-select name="inspection_type" :options="$types"
                                  :selected="old('inspection_type', $inspection->inspection_type)"
                                  placeholder="Not set"/>
                    </x-field>

                    <x-field label="Where" name="location"
                             help="Area, level, or the piece of plant.">
                        <x-input name="location" :value="old('location', $inspection->location)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Scheduled for" name="scheduled_for"
                             help="Leave it blank only for a walk being written up after the fact.">
                        <x-input type="date" name="scheduled_for"
                                 :value="old('scheduled_for', $inspection->scheduled_for?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Accompanied by" name="accompanied_by"
                             help="Anyone walking with the inspector — including people without an account here.">
                        <x-input name="accompanied_by" :value="old('accompanied_by', $inspection->accompanied_by)"/>
                    </x-field>
                </div>
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
                              :selected="old('project_id', $inspection->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $inspection->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $inspection->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="Inspection findings are ordinarily internal — the crew being inspected has to be able to read them.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $inspection->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="hse_inspection" :record="$inspection" class="card mt-5"/>
