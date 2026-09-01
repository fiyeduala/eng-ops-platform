{{--
    Requesting an inspection, or amending a request nobody has walked out on yet.

    THE VERDICT IS NOT ON THIS FORM. `result`, `comments`, `inspected_at` and `inspected_by` are
    written by the inspection endpoint and nothing else, because requesting an inspection and passing
    one are different acts by different people with different authority — and a register in which the
    person asking for the sign-off could type the sign-off is not a quality system (§84).

    NEITHER IS THE CHECKLIST. Acceptance criteria are set through their own screen, before the walk,
    and are deliberately a separate step: criteria written after the fact are a description of what
    was found rather than a test the work had to meet.

    `required_by` IS THE FIELD THAT MOVES PEOPLE. `scheduled_for` is a plan and everybody's plan
    slips; `required_by` is the date past which the work behind this inspection is being held up, and
    it is what the register sorts and colours by.

    Expects: $inspection, $projects, $units, $people, $confidentialities, $types
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The work being offered</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required>
                    <x-input name="title" :value="old('title', $inspection->title)" required/>
                </x-field>

                <x-field label="What is being presented" name="description"
                         help="What has been completed and is now ready to be looked at.">
                    <x-textarea name="description" rows="5">{{ old('description', $inspection->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Inspection type" name="inspection_type"
                             help="Hold point, witness point, surveillance — whatever the contract calls them.">
                        <x-select name="inspection_type" :options="$types"
                                  :selected="old('inspection_type', $inspection->inspection_type)"
                                  placeholder="Not typed"/>
                    </x-field>

                    {{-- Free text, not a lookup. ITP line references are client-formatted strings
                         like "ITP-CIV-004 §3.2" that no table in here would survive contact with. --}}
                    <x-field label="ITP reference" name="itp_reference"
                             help="The Inspection and Test Plan line this inspection discharges.">
                        <x-input name="itp_reference" :value="old('itp_reference', $inspection->itp_reference)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Activity" name="activity"
                             help="The operation itself — 'rebar fixing, pile cap P12'.">
                        <x-input name="activity" :value="old('activity', $inspection->activity)"/>
                    </x-field>

                    <x-field label="Where" name="location"
                             help="Somebody has to walk to this. Make it findable.">
                        <x-input name="location" :value="old('location', $inspection->location)"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">When</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- A time, because inspections are called for a point in the shift and "some
                         time on Thursday" is not something a client's inspector can attend. --}}
                    <x-field label="Scheduled for" name="scheduled_for"
                             help="When you are asking for somebody to attend.">
                        <x-input type="datetime-local" name="scheduled_for"
                                 :value="old('scheduled_for', $inspection->scheduled_for?->format('Y-m-d\TH:i'))"/>
                    </x-field>

                    <x-field label="Required by" name="required_by"
                             help="The date past which the work behind this is held up. This is what the register chases.">
                        <x-input type="date" name="required_by"
                                 :value="old('required_by', $inspection->required_by?->format('Y-m-d'))"/>
                    </x-field>
                </div>

                {{-- Free text and deliberately so: the witness is very often the client's engineer
                     or a third-party surveyor, and neither will ever have an account here. --}}
                <x-field label="To be witnessed by" name="witnessed_by"
                         help="The client, consultant or third party who is being invited to attend, if any.">
                    <x-input name="witnessed_by" :value="old('witnessed_by', $inspection->witnessed_by)"/>
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
                              :selected="old('project_id', $inspection->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $inspection->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $inspection->org_unit_id)"
                              placeholder="No unit"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="Any NCR raised from this inspection starts no more open than the inspection itself.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $inspection->confidentiality?->value)" required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
