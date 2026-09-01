{{--
    Telling the regulator.

    THIS IS NOT ON THE EDIT FORM ON PURPOSE. Flagging an incident as reportable is a different act
    from correcting its description, so it is governed by `hse_incident.investigate` rather than
    `.edit`, and the people trusted with it are a smaller set. The card only renders for them; the
    route enforces it regardless.

    NO COUNTRY'S RULES ARE HARD-CODED HERE. What is reportable, to whom and within how many hours
    differs in every jurisdiction this application will be deployed in, so the system records the
    judgement and the reference number and does not pretend to make the judgement (§47).

    Expects (from incidents/show): $incident
--}}
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Reportable to the authorities</h3>
    </div>

    <form method="POST" action="{{ route('incidents.reportable', $incident) }}" class="space-y-5 p-5">
        @csrf

        <p class="text-sm text-[var(--text-muted)]">
            @if ($incident->is_reportable)
                Already flagged. Saving again updates the date and reference — use it when the
                notification actually goes in, or when the regulator gives you a case number.
            @else
                Flagging it records a decision that was made by a person. Leave the date blank if
                the notification has not gone in yet.
            @endif
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-field label="Reported on" name="reported_to_authority_on">
                <x-input type="date" name="reported_to_authority_on"
                         :value="old('reported_to_authority_on', $incident->reported_to_authority_on?->format('Y-m-d'))"/>
            </x-field>

            <x-field label="Their reference" name="authority_reference">
                <x-input name="authority_reference"
                         :value="old('authority_reference', $incident->authority_reference)"/>
            </x-field>
        </div>

        <button type="submit" class="btn btn-secondary">
            {{ $incident->is_reportable ? 'Update the notification' : 'Flag as reportable' }}
        </button>
    </form>
</div>
