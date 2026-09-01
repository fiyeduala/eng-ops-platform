{{--
    Raising a non-conformance, or amending one nobody has answered yet.

    THE REQUIREMENT IS REQUIRED AND THE DESCRIPTION IS NOT. That is the wrong way round everywhere
    else in this application and it is deliberate here. "The welds are bad" is an opinion; "AWS D1.1
    Table 6.1 requires undercut not exceeding 1mm, measured 2.5mm" is a finding — and only the second
    can be argued about, dispositioned, or defended two years later by somebody holding a failed
    component. An NCR that cannot name the clause it breaches is a complaint.

    THE DISPOSITION IS NOT ON THIS FORM. Rework, repair, use-as-is, reject and regrade are the
    engineering answer to the finding, they carry their own permission and their own endpoint, and
    the service refuses to let the person who detected the non-conformance decide it can be left as
    it is — which is exactly what a disposition field here would allow (§84). Neither is the
    response, the verification or the closure: five acts, five endpoints, five people.

    `cost_impact` IS A PLAIN NUMBER WITH NO CURRENCY BESIDE IT. §47 forbids baking a country's
    financial assumptions into the schema; the company's own books say what the figure is in. It
    stays blank when nobody priced it, because a zero would read as "this cost nothing".

    Expects: $ncr, $projects, $units, $people, $confidentialities, $severities, $categories, $sources
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The finding</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Title" name="title" required>
                    <x-input name="title" :value="old('title', $ncr->title)" required/>
                </x-field>

                <x-field label="Requirement breached" name="requirement" required
                         help="The specification, drawing or standard clause, and the measurement against it. This is the field the whole record rests on.">
                    <x-textarea name="requirement" rows="5" required
                                placeholder="AWS D1.1 Table 6.1 — undercut not to exceed 1mm. Measured 2.5mm over 300mm at joint W-114.">{{ old('requirement', $ncr->requirement) }}</x-textarea>
                </x-field>

                <x-field label="What was found" name="description"
                         help="Context, extent, how it was discovered. Optional — the requirement above is what matters.">
                    <x-textarea name="description" rows="4">{{ old('description', $ncr->description) }}</x-textarea>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Where" name="location">
                        <x-input name="location" :value="old('location', $ncr->location)"/>
                    </x-field>

                    <x-field label="Raised against" name="party_name"
                             help="The subcontractor, supplier or fabricator responsible. A name, not a lookup.">
                        <x-input name="party_name" :value="old('party_name', $ncr->party_name)"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Classification</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Severity" name="severity"
                             help="What the workflow routes on. Configure the list, and the routing follows it.">
                        <x-select name="severity" :options="$severities"
                                  :selected="old('severity', $ncr->severity)" placeholder="Not graded"/>
                    </x-field>

                    <x-field label="Category" name="category">
                        <x-select name="category" :options="$categories"
                                  :selected="old('category', $ncr->category)" placeholder="Not categorised"/>
                    </x-field>
                </div>

                <x-field label="Source" name="source"
                         help="Where the finding came from. An NCR raised from an inspection sets this itself.">
                    <x-select name="source" :options="$sources"
                              :selected="old('source', $ncr->source)" placeholder="Not stated"/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Detected on" name="detected_on">
                        <x-input type="date" name="detected_on"
                                 :value="old('detected_on', $ncr->detected_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Response required by" name="required_by"
                             help="The date the register chases. Must not be before the date it was found.">
                        <x-input type="date" name="required_by"
                                 :value="old('required_by', $ncr->required_by?->format('Y-m-d'))"/>
                    </x-field>
                </div>

                <x-field label="Cost impact" name="cost_impact"
                         help="Leave blank when nobody has priced it. A zero here reads as 'this cost nothing', which is a different claim.">
                    <x-input type="number" step="0.01" name="cost_impact"
                             :value="old('cost_impact', $ncr->cost_impact)"/>
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
                              :selected="old('project_id', $ncr->project_id)" placeholder="No project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $ncr->discipline_id)" placeholder="No discipline"/>
                </x-field>

                <x-field label="Owning unit" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $ncr->org_unit_id)" placeholder="No unit"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality" required
                         help="An NCR is frequently read by the party it is raised against. Classify it for the people who have to act on it.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $ncr->confidentiality?->value)" required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
