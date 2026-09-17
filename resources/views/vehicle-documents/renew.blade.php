{{--
    Next year's certificate.

    THE OLD CERTIFICATE DETAILS ARE RETAINED. A renewal is a NEW linked record on the same vehicle,
    with an effective replacement date — which lets somebody answer "was this lorry insured on
    the fourteenth of March" two years later. Overwriting the dates on the old row would answer that
    question with today's policy and sound entirely convincing.

    THE NUMBER AND THE SCAN START BLANK, deliberately. The type and the issuer are inherited because
    they genuinely carry over; a certificate number and a signed scan do not, and copying either into
    next year's record would be a forgery in a register somebody may one day rely on.

    Expects: $document (the certificate being replaced), $renewal (the pre-filled new one),
             $vehicles, $types, $confidentialities
--}}
<x-layouts.app :title="'Renew '.$document->typeLabel()">

    <x-page-header
        :title="'Renew '.$document->typeLabel()"
        :description="$document->vehicle?->registration_number"/>

    <div class="card mb-5 p-4 text-sm text-[var(--text-muted)]">
        Replacing the certificate
        @if ($document->document_number)
            numbered <span class="font-mono text-[var(--text-strong)]">{{ $document->document_number }}</span>,
        @endif
        @if ($document->expires_on)
            which {{ $document->hasExpired() ? 'expired' : 'expires' }}
            <span class="font-medium text-[var(--text-strong)]">{{ $document->expires_on->format('j M Y') }}</span>.
        @else
            which carries no expiry date.
        @endif
        Its certificate details and scan stay in the history. Its expiry warnings stop when the new certificate takes effect.
    </div>

    <form method="POST" action="{{ route('vehicle-documents.renew', $document) }}"
          enctype="multipart/form-data" class="space-y-6">
        @csrf

        @include('vehicle-documents.partials.form', [
            'document' => $renewal,
            'vehicleLocked' => true,
            'renewing' => true,
            'lockedVehicle' => $document->vehicle?->registration_number,
        ])

        <div class="flex items-center gap-3">
            <button type="submit" class="btn btn-primary">File the renewal</button>
            <a href="{{ route('vehicle-documents.show', $document) }}" class="btn btn-ghost">Cancel</a>
        </div>
    </form>

</x-layouts.app>
