{{--
    A transmittal's covering details. The revisions themselves are added on the transmittal's own
    page, after it exists — a form that tried to do both would ask somebody to pick documents before
    the project that filters them had been chosen.

    THE ADDRESSEE IS FREE TEXT ON PURPOSE. Half of all real transmittals go to somebody the platform
    has never heard of: a regulator, a joint-venture partner, one named engineer at a firm we deal
    with once. A form that could only address existing records is how a document register fills up
    with invented client entries.

    Expects: $transmittal, $projects, $units, $purposes, $transports, $confidentialities, $nextNumber
--}}
<div class="space-y-6">

    {{-- ---------------------------------------------------------------- what it is --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">What is being sent</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Subject" name="subject" required
                     help="What this covers, as the other side will read it on the cover sheet.">
                <x-input name="subject" :value="$transmittal->subject" required maxlength="250"
                         placeholder="e.g. Foundation drawings issued for construction"/>
            </x-field>

            <x-field label="Purpose" name="purpose" required
                     help="This has contractual weight. Issued for construction is not issued for comment, and a register that cannot tell them apart is useless in the dispute it exists to survive.">
                <x-select name="purpose" :options="$purposes" :selected="old('purpose', $transmittal->purpose)"/>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="Project" name="project_id"
                         help="Optional, but setting it filters the revision picker to that project's documents.">
                    <x-select name="project_id" :options="$projects"
                              :selected="old('project_id', $transmittal->project_id)"
                              placeholder="Not against a project"/>
                </x-field>

                <x-field label="Issuing unit" name="org_unit_id"
                         help="Which part of the organisation is sending it. Used for access as well as for the record.">
                    <x-select name="org_unit_id" :options="$units"
                              :selected="old('org_unit_id', $transmittal->org_unit_id)"
                              placeholder="No particular unit"/>
                </x-field>
            </div>

            <x-field label="Confidentiality" name="confidentiality" required
                     help="Inherited by the cover sheet. It is raised automatically at issue if a document on it is more confidential than this — a transmittal is never less confidential than what it carries.">
                <x-select name="confidentiality" :options="$confidentialities"
                          :selected="old('confidentiality', $transmittal->confidentiality?->value)"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- addressee --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Who it goes to</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Organisation or person" name="to_name" required
                     help="Type it as it should appear on the cover sheet. There is no need for them to exist as a record here.">
                <x-input name="to_name" :value="$transmittal->to_name" required maxlength="200"
                         placeholder="e.g. Lagos State Ministry of Works"/>
            </x-field>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field label="For the attention of" name="to_attention"
                         help="The named individual, if there is one.">
                    <x-input name="to_attention" :value="$transmittal->to_attention" maxlength="200"/>
                </x-field>

                <x-field label="Email" name="to_email"
                         help="Recorded for the file. Nothing is emailed from here — the recipient has no account and no policy governs them, so the platform will not send them a link.">
                    <x-input name="to_email" type="email" :value="$transmittal->to_email" maxlength="200"/>
                </x-field>
            </div>

            <x-field label="Address" name="to_address"
                     help="Needed when the transport is courier or by hand, and useful on the cover sheet either way.">
                <x-textarea name="to_address" :value="$transmittal->to_address" rows="3"/>
            </x-field>

            <x-field label="How it is being sent" name="transport">
                <x-select name="transport" :options="$transports" :selected="old('transport', $transmittal->transport)"
                          placeholder="Not recorded"/>
            </x-field>
        </div>
    </div>

    {{-- ---------------------------------------------------------------- covering note and response --}}
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Covering note and response</h3>
        </div>

        <div class="space-y-5 p-5">
            <x-field label="Covering note" name="cover_note"
                     help="Printed on the cover sheet above the schedule of documents.">
                <x-textarea name="cover_note" :value="$transmittal->cover_note" rows="5"/>
            </x-field>

            <label class="flex items-start gap-3">
                <input type="hidden" name="requires_acknowledgement" value="0">
                <input type="checkbox" name="requires_acknowledgement" value="1"
                       @checked(old('requires_acknowledgement', $transmittal->requires_acknowledgement))
                       class="mt-0.5 h-4 w-4 rounded border-[var(--surface-border)]">
                <span class="text-sm">
                    <span class="font-medium text-[var(--text-strong)]">Receipt must be confirmed</span>
                    <span class="block text-[var(--text-muted)]">
                        It then appears under Awaiting acknowledgement until somebody here records
                        the confirmation that came back.
                    </span>
                </span>
            </label>

            <x-field label="Response due by" name="due_response_on"
                     help="Optional. Used to flag the transmittal as overdue if no receipt has been recorded by then.">
                <x-input name="due_response_on" type="date"
                         :value="$transmittal->due_response_on?->format('Y-m-d')"/>
            </x-field>
        </div>
    </div>
</div>
