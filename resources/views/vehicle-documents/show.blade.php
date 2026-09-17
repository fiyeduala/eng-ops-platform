{{--
    One certificate.

    THE DOWNLOAD IS GUARDED BY `view` ON THIS RECORD AND NOTHING ELSE (§67). A vehicle document IS
    its scan — the row is an index card describing a piece of paper — so a separate download right
    would produce the odd position of showing somebody the policy number on screen while withholding
    the picture of it. Reaching this page already means `view`; the only remaining question is
    whether a file was ever attached.

    RENEWING IS NOT EDITING. Correcting a typo in a policy number is `vehicle_document.edit`; filing
    next year's cover is `vehicle_document.renew`, and it writes a NEW row rather than moving this
    one's dates — which is what lets somebody answer "was this lorry insured in March" two years from
    now.

    Expects: $document, $timeline, $canEdit, $canDelete, $canRenew, $canDownload, $types,
             $confidentialities
--}}
<x-layouts.app :title="$document->typeLabel()">

    <x-page-header :title="$document->typeLabel()"
                   :description="$document->vehicle?->registration_number">
        <x-slot:actions>
            @if ($document->hasExpired())
                <x-badge tone="danger">Expired</x-badge>
            @elseif ($document->expires_on === null)
                <x-badge tone="neutral">No expiry</x-badge>
            @else
                <x-badge tone="success">In date</x-badge>
            @endif

            @if ($document->vehicle)
                <a href="{{ route('vehicles.show', $document->vehicle) }}" class="btn btn-ghost btn-sm">
                    <x-icon name="truck" class="h-3.5 w-3.5"/> {{ $document->vehicle->registration_number }}
                </a>
            @endif

            @if ($canDownload)
                <a href="{{ route('vehicle-documents.download', $document) }}" class="btn btn-secondary btn-sm">
                    <x-icon name="download" class="h-3.5 w-3.5"/> Download the scan
                </a>
            @endif

            @if ($canEdit)
                <a href="{{ route('vehicle-documents.edit', $document) }}" class="btn btn-ghost btn-sm">Edit</a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($document->hasExpired())
        <div class="card mb-5 border-rose-300 bg-rose-50 p-4 text-sm text-rose-900
                    dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
            This certificate expired on {{ $document->expires_on?->format('j M Y') }}. The vehicle
            should not be on the road until it has been renewed.
        </div>
    @elseif ($document->daysRemaining() !== null && $document->daysRemaining() <= 30)
        <div class="card mb-5 border-amber-300 bg-amber-50 p-4 text-sm text-amber-900
                    dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            {{ $document->daysRemaining() }}
            {{ \Illuminate\Support\Str::plural('day', $document->daysRemaining()) }} left on this
            certificate.
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">

            <div class="card">
                <div class="card-header"><h3 class="card-title">The certificate</h3></div>
                <dl class="grid gap-4 p-5 sm:grid-cols-2">
                    <x-register.fact label="Kind" :value="$document->typeLabel()"/>
                    <x-register.fact label="Number" :value="$document->document_number" mono/>
                    <x-register.fact label="Issued by" :value="$document->issuer"/>
                    <x-register.fact label="Cost"
                                     :value="$document->cost !== null ? number_format((float) $document->cost, 2) : null" mono/>
                    <x-register.fact label="Issued on" :value="$document->issued_on?->format('j M Y')"/>
                    <x-register.fact label="Expires on">
                        @if ($document->expires_on)
                            <span @class(['font-medium text-rose-600' => $document->hasExpired()])>
                                {{ $document->expires_on->format('j M Y') }}
                            </span>
                        @else
                            {{-- Spelled out, because a dash here would read as an unfilled field. --}}
                            <span class="text-[var(--text-muted)]">Does not expire</span>
                        @endif
                    </x-register.fact>
                </dl>

                @if ($document->notes)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <p class="mb-1 text-xs uppercase tracking-wide text-[var(--text-muted)]">Notes</p>
                        <p class="whitespace-pre-line text-sm text-[var(--text-body)]">{{ $document->notes }}</p>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title">The scan</h3></div>
                @if ($canDownload)
                    <div class="flex flex-wrap items-center justify-between gap-3 p-5">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm text-[var(--text-strong)]">
                                {{ $document->file_name }}
                            </p>
                            <p class="text-xs text-[var(--text-muted)]">
                                {{ $document->file_mime }}
                                @if ($document->file_size)
                                    · {{ number_format($document->file_size / 1024, 0) }} KB
                                @endif
                            </p>
                        </div>

                        <a href="{{ route('vehicle-documents.download', $document) }}"
                           class="btn btn-secondary btn-sm">
                            <x-icon name="download" class="h-3.5 w-3.5"/> Download
                        </a>
                    </div>
                    <div class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-[var(--text-muted)]">
                        Held in private storage and served only through this application. Every
                        download is authorised against this record and recorded on the trail below.
                    </div>
                @else
                    <x-empty-state
                        icon="file-text"
                        title="No scan attached"
                        description="The particulars are recorded but the certificate itself has not been uploaded. Editing this record lets you attach it."/>
                @endif
            </div>

            <x-timeline :entries="$timeline" empty="Nothing has happened to this certificate yet."/>
        </div>

        <div class="space-y-6">
            @if ($canRenew)
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Renewal</h3></div>
                    <div class="p-5">
                        <p class="mb-3 text-sm text-[var(--text-muted)]">
                            Filing next year's cover creates a new record beside this one. This
                            certificate is kept exactly as it is.
                        </p>
                        <a href="{{ route('vehicle-documents.renew-form', $document) }}"
                           class="btn btn-primary btn-sm w-full">
                            <x-icon name="calendar" class="h-3.5 w-3.5"/> Renew this certificate
                        </a>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h3 class="card-title">Particulars</h3></div>
                <dl class="space-y-4 p-5">
                    <x-register.fact label="Vehicle">
                        @if ($document->vehicle)
                            <a href="{{ route('vehicles.show', $document->vehicle) }}"
                               class="font-mono hover:text-brand-600">
                                {{ $document->vehicle->registration_number }}
                            </a>
                        @else
                            —
                        @endif
                    </x-register.fact>
                    <x-register.fact label="Confidentiality"
                                     :value="$document->confidentiality?->label()"/>
                    <x-register.fact label="Filed by" :value="$document->creator?->name"/>
                    <x-register.fact label="Filed on" :value="$document->created_at?->format('j M Y')"/>
                </dl>

                @if ($canDelete)
                    <div class="border-t border-[var(--surface-border)] p-5">
                        <x-delete-form :action="route('vehicle-documents.destroy', $document)"
                                       label="Delete this certificate"
                                       confirm="Deleting removes the certificate from the register. Continue?"/>
                    </div>
                @endif
            </div>
        </div>
    </div>

</x-layouts.app>
