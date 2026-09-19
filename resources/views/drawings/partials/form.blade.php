{{--
    A drawing's title block.

    THE NUMBER IS TYPED, NOT GENERATED — the one register in this phase where that is true. A drawing
    number encodes the project, area, discipline and sheet in a convention agreed with the client
    before this platform existed, and is frequently issued BY the client. The field opens with a
    generated suggestion so a company with no convention gets one for free, and everybody else types
    what is actually in the corner of the sheet.

    SHEET SIZES ARE NOT CONFIGURABLE. A0 through A4 and the ANSI/ARCH series are an international
    standard, not a company preference, and a company-editable list here would only ever produce
    typos to report on.

    Expects: $drawing, $projects, $units, $people, $purposes, $sheetSizes, $deliverables,
             $confidentialities
--}}
<div class="space-y-6">

    {{-- ---------------------------------------------------------------- title block --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Title block</h3>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Drawing number" name="number" required
                         help="As it appears on the sheet. This is what people will search for.">
                    <x-input name="number" :value="$drawing->number" required maxlength="120"
                             class="font-mono"/>
                </x-field>

                <x-field label="Revision" name="revision"
                         help="The current revision. Moving to the next one is done from the drawing's own page, so the change is recorded.">
                    <x-input name="revision" :value="$drawing->revision" maxlength="16" placeholder="e.g. A"/>
                </x-field>
            </div>

            <x-field label="Title" name="title" required
                     help="The drawing title, as printed.">
                <x-input name="title" :value="$drawing->title" required maxlength="250"
                         placeholder="e.g. Ground floor — general arrangement"/>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-3">
                <x-field label="Sheet" name="sheet_number">
                    <x-input type="number" name="sheet_number" min="1" :value="$drawing->sheet_number"/>
                </x-field>

                <x-field label="Of" name="sheet_count" help="Total sheets in the set.">
                    <x-input type="number" name="sheet_count" min="1" :value="$drawing->sheet_count"/>
                </x-field>

                <x-field label="Sheet size" name="sheet_size">
                    <x-select name="sheet_size" :options="$sheetSizes"
                              :selected="old('sheet_size', $drawing->sheet_size)" placeholder="Not stated"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Scale" name="scale" help="Free text — &quot;1:50&quot;, &quot;NTS&quot;, &quot;As shown&quot;.">
                    <x-input name="scale" :value="$drawing->scale" maxlength="60" placeholder="e.g. 1:50"/>
                </x-field>

                <x-field label="Issue purpose" name="purpose"
                         help="This carries contractual weight. For construction is not for comment.">
                    <x-select name="purpose" :options="$purposes" :selected="old('purpose', $drawing->purpose)"
                              placeholder="Not stated"/>
                </x-field>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- who and where --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Who and where</h3>
        </div>

        <div class="space-y-5 p-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Drawn by" name="drawn_by">
                    <x-select name="drawn_by" :options="$people" :selected="old('drawn_by', $drawing->drawn_by)"
                              placeholder="Nobody yet"/>
                </x-field>

                <x-field label="Checked by" name="checked_by"
                         help="Naming a checker does not perform the check. It records who is responsible for it.">
                    <x-select name="checked_by" :options="$people" :selected="old('checked_by', $drawing->checked_by)"
                              placeholder="Not checked"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $drawing->project_id)"
                              placeholder="Not against a project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $drawing->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>
            </div>

            <x-field label="Deliverable" name="deliverable_id"
                     help="Optional. Links this sheet to the promise on the deliverables register that it satisfies. Only deliverables you can reach are listed.">
                <x-select name="deliverable_id" :options="$deliverables"
                          :selected="old('deliverable_id', $drawing->deliverable_id)"
                          placeholder="Not against a deliverable"/>
            </x-field>

            <x-field label="Confidentiality" name="confidentiality">
                <x-select name="confidentiality" :options="$confidentialities"
                          :selected="old('confidentiality', $drawing->confidentiality?->value)"/>
            </x-field>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="drawing" :record="$drawing" class="card mt-5"/>
