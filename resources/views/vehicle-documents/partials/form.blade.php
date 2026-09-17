{{--
    One certificate, filed, corrected, or renewed for another year.

    ONE PARTIAL, THREE ROUTES. A renewal is the same paperwork from the same authority with two
    things changed, so it uses the same fields; what differs is where it posts. The renew page
    arrives with the type, the issuer and the start date already filled in — and deliberately WITHOUT
    the old certificate's number or its scan, because copying either into next year's record would be
    a forgery in a register somebody may one day rely on.

    THE EXPIRY MAY BE BLANK AND THAT MEANS SOMETHING. A proof of ownership does not lapse. A null
    here is "this does not expire", not "nobody typed it in" — and the register says so in words for
    exactly that reason.

    THE SCAN GOES TO PRIVATE STORAGE (§67). It is never web-reachable; the download route re-runs the
    policy against the record every time, and the file is checked on extension, MIME and size in the
    service, where a seeder and an import meet the same rules.

    Expects: $document (the row being written), $vehicles, $types, $confidentialities,
             optionally $vehicleLocked (true on edit and renew, where the vehicle cannot change)
--}}
@php $locked = $vehicleLocked ?? $document->exists; @endphp

<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="card-header"><h3 class="card-title">The certificate</h3></div>
            <div class="space-y-5 p-5">
                @if ($locked)
                    <x-field label="Vehicle" name="vehicle_display"
                             help="A certificate cannot be moved to a different vehicle.">
                        <input type="text" id="vehicle_display" class="input font-mono" disabled
                               value="{{ $lockedVehicle ?? ($document->vehicle?->registration_number ?? 'Unknown vehicle') }}">
                    </x-field>
                @else
                    <x-field label="Vehicle" name="vehicle_id" required>
                        <x-select name="vehicle_id" :options="$vehicles"
                                  :selected="old('vehicle_id', $document->vehicle_id)" required
                                  placeholder="Choose the vehicle"/>
                    </x-field>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Kind of certificate" name="document_type" required
                             help="Your own list — edit it in Settings. What a fleet must carry differs by country and by what the vehicle does.">
                        <x-select name="document_type" :options="$types"
                                  :selected="old('document_type', $document->document_type)" required
                                  :disabled="($renewing ?? false) || $document->renews_id !== null"
                                  placeholder="Choose"/>
                        @if (($renewing ?? false) || $document->renews_id !== null)
                            <input type="hidden" name="document_type" value="{{ $document->document_type }}">
                        @endif
                    </x-field>

                    <x-field label="Certificate number" name="document_number"
                             help="Deliberately blank on a renewal — next year's paper has its own number.">
                        <x-input name="document_number" maxlength="120" class="input font-mono"
                                 :value="old('document_number', $document->document_number)"/>
                    </x-field>
                </div>

                <x-field label="Issued by" name="issuer"
                         help="The insurer, the licensing authority, the agency.">
                    <x-input name="issuer" maxlength="200"
                             :value="old('issuer', $document->issuer)"/>
                </x-field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-field label="Issued on" name="issued_on">
                        <x-input type="date" name="issued_on"
                                 :readonly="$document->renews_id !== null"
                                 :value="old('issued_on', $document->issued_on?->toDateString())"/>
                    </x-field>

                    <x-field label="Expires on" name="expires_on"
                             help="Leave blank for something that does not lapse — a proof of ownership, for instance.">
                        <x-input type="date" name="expires_on"
                                 :value="old('expires_on', $document->expires_on?->toDateString())"/>
                    </x-field>

                    <x-field label="Cost" name="cost"
                             help="What the certificate cost to obtain.">
                        <x-input type="number" name="cost" step="0.01" min="0"
                                 :value="old('cost', $document->cost)"/>
                    </x-field>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">The scan</h3></div>
            <div class="space-y-5 p-5">
                @if ($document->exists && $document->hasFile())
                    <p class="text-sm text-[var(--text-muted)]">
                        A scan is already attached
                        (<span class="font-mono">{{ $document->file_name }}</span>).
                        Choosing a file below replaces it.
                    </p>
                @endif

                <x-field label="Upload" name="file"
                         help="A PDF or a photograph, at most 10MB. Photograph the certificate rather than scanning it at full resolution. It is stored privately and served only through this application.">
                    <input type="file" name="file" id="file" class="input"
                           accept=".pdf,.jpg,.jpeg,.png,.webp,.heic">
                </x-field>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Access</h3></div>
            <div class="space-y-5 p-5">
                <x-field label="Confidentiality" name="confidentiality" required
                         help="A certificate carries a policy number and often an address. Restricting one hides it from everybody outside the unit that owns the vehicle.">
                    <x-select name="confidentiality" :options="$confidentialities"
                              :selected="old('confidentiality', $document->confidentiality?->value)" required/>
                </x-field>

                <x-field label="Notes" name="notes">
                    <x-textarea name="notes" :value="old('notes', $document->notes)" rows="4"/>
                </x-field>
            </div>
        </div>
    </div>
</div>
