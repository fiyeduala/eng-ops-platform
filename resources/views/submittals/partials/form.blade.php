{{--
    The header of a package. Not its contents, and never its verdict.

    THE CONTENTS ARE ADDED ON THE PACKAGE'S OWN PAGE, one line at a time, because each line that
    names a document revision has to pass that document's own policy on the way in. A form that
    let somebody type a list of revision ids would be a way to staple a confidential drawing to a
    package and post it to a client (§67).

    THERE IS NO REVIEW CODE FIELD, and there never will be. The reviewer stamps the package from
    the review card, behind `submittal.review`. A code on this form would let the party being
    reviewed mark their own package approved (§84).

    Expects: $submittal, $projects, $units, $people, $types, $confidentialities
--}}
<div class="space-y-6">

    {{-- ---------------------------------------------------------------- the package --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">The package</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Title" name="title" required
                     help="What is being submitted, as the reviewer will read it on their own register.">
                <x-input name="title" :value="$submittal->title" required maxlength="300"
                         placeholder="e.g. Fire pump set — vendor data and test certificates"/>
            </x-field>

            <x-field label="Description" name="description"
                     help="Anything the reviewer needs before they open the contents — the specification section, the deviation you are asking them to accept.">
                <x-textarea name="description" rows="4">{{ old('description', $submittal->description) }}</x-textarea>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Type" name="type"
                         help="Your company's own list. Material, method statement, sample — whatever your contracts call these.">
                    <x-select name="type" :options="$types" :selected="old('type', $submittal->type)"
                              placeholder="Unclassified"/>
                </x-field>

                <x-field label="Revision" name="revision"
                         help="The revision of the thing being submitted. A resubmittal gets a new number of its own, so this is not an attempt counter.">
                    <x-input name="revision" :value="$submittal->revision" maxlength="16" placeholder="0"/>
                </x-field>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- who is reviewing --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Who is reviewing it</h3>
        </div>

        <div class="space-y-5 p-5">
            @include("partials.party-picker", [
                "typeName" => "party_type",
                "idName" => "party_id",
                "nameName" => "party_name",
                "label" => "Reviewing party",
                "record" => $submittal,
                "help" => "Type the name — the reviewer is often somebody this platform has never heard of, a consultant or one named engineer at the client. Link it to a register when there is a record to link to.",
            ])

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $submittal->project_id)"
                              placeholder="Not against a project"/>
                </x-field>

                <x-field label="Discipline" name="discipline_id">
                    <x-select name="discipline_id" :options="$units"
                              :selected="old('discipline_id', $submittal->discipline_id)"
                              placeholder="No discipline"/>
                </x-field>
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Department" name="org_unit_id"
                         help="The unit that owns the package. This is what decides who else in the company can see it.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $submittal->org_unit_id)"
                              placeholder="No department"/>
                </x-field>

                <x-field label="Confidentiality" name="confidentiality">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $submittal->confidentiality?->value)"/>
                </x-field>
            </div>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- the dates --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">The dates</h3>
        </div>

        <div class="space-y-5 p-5">
            <p class="text-sm text-[var(--text-muted)]">
                The date submitted is filled in for you when the package is actually submitted. Setting
                it by hand here is for packages that went out before this register existed.
            </p>

            <div class="grid gap-5 sm:grid-cols-3">
                <x-field label="Submitted by" name="submitted_by"
                         help="The engineer answerable for the package.">
                    <x-select name="submitted_by" :options="$people"
                              :selected="old('submitted_by', $submittal->submitted_by)"
                              placeholder="Nobody"/>
                </x-field>

                <x-field label="Submitted on" name="submitted_on">
                    <x-input type="date" name="submitted_on"
                             :value="old('submitted_on', $submittal->submitted_on?->toDateString())"/>
                </x-field>

                <x-field label="Review due by" name="required_by"
                         help="Suggested from your company's standard review period.">
                    <x-input type="date" name="required_by"
                             :value="old('required_by', $submittal->required_by?->toDateString())"/>
                </x-field>
            </div>
        </div>
    </div>
</div>


{{-- The fields this company added to this register (§73). Renders nothing until
     somebody defines one on Configuration → Custom fields. --}}
<x-custom-fields.form entity-type="submittal" :record="$submittal" class="card mt-5"/>
