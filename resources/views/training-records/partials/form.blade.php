{{--
    Recording a certificate.

    THE HOLDER IS TWO FIELDS AND THAT IS DELIBERATE. `user_id` links an account; `person_name`
    carries subcontractors, agency labour and visiting specialists who have no account here and
    never will — and those are frequently the people whose tickets matter most on a site. One of the
    two is required; neither alone is.

    THE EXPIRY IS A STORED FACT, NOT A CALCULATION. What the certificate in somebody's hand actually
    says wins over what the catalogue would have worked out, so the field is editable — and once
    saved, a later change to the course's validity cannot revise it (§59).

    THE CERTIFICATE FILE IS A DOCUMENT, NOT AN UPLOAD. It is picked from document control, where the
    download is authorized (§67); this register never becomes a second file store.

    Expects: $record, $courses, $results, $people, $projects, $units, $confidentialities,
             $certificates
--}}
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Who and what</h3>
            </div>
            <div class="space-y-5 p-5">
                <x-field label="Course" name="training_course_id"
                         help="Only courses still in use are listed. Retired ones stay on the records already written against them.">
                    <x-select name="training_course_id" :options="$courses"
                              :selected="old('training_course_id', $record->training_course_id)"
                              placeholder="No course"/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Person with an account" name="user_id"
                             help="Leave blank for somebody who has no account here.">
                        <x-select name="user_id" :options="$people"
                                  :selected="old('user_id', $record->user_id)"
                                  placeholder="Not an account holder"/>
                    </x-field>

                    <x-field label="Or their name" name="person_name"
                             help="Required if no account is linked.">
                        <x-input name="person_name" :value="old('person_name', $record->person_name)"/>
                    </x-field>
                </div>

                <x-field label="Company" name="company"
                         help="Their employer, if it is not this one.">
                    <x-input name="company" :value="old('company', $record->company)"/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Completed on" name="completed_on">
                        <x-input type="date" name="completed_on"
                                 :value="old('completed_on', $record->completed_on?->format('Y-m-d'))"/>
                    </x-field>

                    <x-field label="Expires on" name="expires_on"
                             help="Leave blank if this ticket does not lapse.">
                        <x-input type="date" name="expires_on"
                                 :value="old('expires_on', $record->expires_on?->format('Y-m-d'))"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">The certificate</h3>
            </div>
            <div class="space-y-5 p-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Certificate number" name="certificate_number">
                        <x-input name="certificate_number"
                                 :value="old('certificate_number', $record->certificate_number)"
                                 class="font-mono"/>
                    </x-field>

                    <x-field label="Scanned certificate" name="document_id"
                             help="Picked from document control, where the download is authorized.">
                        <x-select name="document_id" :options="$certificates"
                                  :selected="old('document_id', $record->document_id)"
                                  placeholder="Not filed"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Result" name="result">
                        <x-select name="result" :options="$results"
                                  :selected="old('result', $record->result)"
                                  placeholder="Not recorded"/>
                    </x-field>

                    <x-field label="Score" name="score" help="If the course is marked.">
                        <x-input type="number" name="score" min="0" max="9999" step="0.01"
                                 :value="old('score', $record->score)"/>
                    </x-field>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Provider" name="provider">
                        <x-input name="provider" :value="old('provider', $record->provider)"/>
                    </x-field>

                    <x-field label="Trainer" name="trainer_name">
                        <x-input name="trainer_name" :value="old('trainer_name', $record->trainer_name)"/>
                    </x-field>

                    {{-- No currency symbol and no currency column. What the figure is in is a fact
                         about this company's books, and a symbol this platform guessed would be
                         worse than none (§47). --}}
                    <x-field label="Cost" name="cost" help="In your own currency.">
                        <x-input type="number" name="cost" min="0" step="0.01"
                                 :value="old('cost', $record->cost)"/>
                    </x-field>
                </div>

                <x-field label="Notes" name="notes">
                    <x-textarea name="notes" rows="3">{{ old('notes', $record->notes) }}</x-textarea>
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
                              :selected="old('project_id', $record->project_id)"
                              placeholder="No project"/>
                </x-field>

                <x-field label="Department" name="org_unit_id">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $record->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Who can read it" name="confidentiality" required
                         help="A certificate names a person and often carries a score. Restricted is the honest default.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $record->confidentiality?->value)"
                              required/>
                </x-field>
            </div>
        </div>
    </div>
</div>
