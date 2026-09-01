{{--
    Requesting a permit, or amending one that has not been issued yet.

    NOTHING THAT HAPPENS TO A LIVE PERMIT IS ON THIS FORM. No issue, no acceptance, no suspension,
    no gas-test result, no closure. Each of those is a separate act by a named person behind its own
    permission, and this is the one module where a form field standing in for a signature would be
    dangerous rather than merely untidy (§84). This screen describes the work and asks.

    BOTH DATES CARRY A TIME. Permits are worked to in hours: "valid on Tuesday" is a note, not a
    permit. Leaving the end blank lets the permit type's default duration fill it in.

    THE PRECAUTION CHECKLIST IS NOT EDITED HERE EITHER. It is copied from the chosen permit type
    when the permit is created, and then confirmed line by line on the permit itself — a checklist
    somebody could retype while asking for their own permit is not a control.

    Expects: $permit, $projects, $units, $people, $confidentialities, $permitTypes
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The work</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required>
                    <x-input name="title" :value="old('title', $permit->title)" required/>
                </x-field>

                <x-field label="What is being done" name="description" required
                         help="Whoever issues this permit signs against this field. Say what the work is, how it is being done, and what could go wrong.">
                    <x-textarea name="description" rows="6" required>{{ old('description', $permit->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Permit type" name="permit_type_id"
                             help="Its precautions are copied onto this permit when it is raised.">
                        <x-select name="permit_type_id" :options="$permitTypes"
                                  :selected="old('permit_type_id', $permit->permit_type_id)"
                                  placeholder="Not typed"/>
                    </x-field>

                    <x-field label="Where" name="location" required
                             help="Area, level, or the piece of plant. Somebody walking past has to match this to what they can see.">
                        <x-input name="location" :value="old('location', $permit->location)" required/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Equipment" name="equipment">
                        <x-input name="equipment" :value="old('equipment', $permit->equipment)"/>
                    </x-field>

                    <x-field label="Isolation reference" name="isolation_reference"
                             help="The lock-out or isolation certificate this permit relies on, if there is one.">
                        <x-input name="isolation_reference"
                                 :value="old('isolation_reference', $permit->isolation_reference)"/>
                    </x-field>
                </div>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The window</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Valid from" name="valid_from">
                    <x-input type="datetime-local" name="valid_from"
                             :value="old('valid_from', $permit->valid_from?->format('Y-m-d\TH:i'))"/>
                </x-field>

                <x-field label="Valid to" name="valid_to"
                         help="Leave blank to take the type's default duration.">
                    <x-input type="datetime-local" name="valid_to"
                             :value="old('valid_to', $permit->valid_to?->format('Y-m-d\TH:i'))"/>
                </x-field>

                <x-field label="People working" name="worker_count"
                         help="How many will be on the job under this permit.">
                    <x-input type="number" name="worker_count" min="1" max="2000"
                             :value="old('worker_count', $permit->worker_count)"/>
                </x-field>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Where it sits</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $permit->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $permit->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $permit->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="A permit should almost always be internal. The crew working under it has to be able to read it.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $permit->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
