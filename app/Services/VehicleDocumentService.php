<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\SettingsRepository;
use App\Events\VehicleDocumentExpiring;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\Enums\Confidentiality;
use App\Support\OptionList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Insurance, licences and permits — the paperwork that makes a vehicle legal to move.
 *
 * RENEWING ADDS A ROW; IT DOES NOT EDIT THE OLD ONE. That is the whole module. "Was this truck
 * insured on the 14th of March" is asked after a collision, by somebody who is not on your side,
 * and a register that overwrites `expires_on` each year cannot answer it — it can only say what the
 * position is today. Each certificate keeps its own number, its own dates and its own scan, and
 * `renew()` supersedes rather than replaces.
 *
 * EXPIRY IS A DATE QUERY AND NOTHING ELSE. There is no `is_expired` column, no status vocabulary
 * for this register, and no scheduled command that marks anything. `hasExpired()` compares
 * `expires_on` to today every time it is asked; the register's tabs, the vehicle page and the sweep
 * below all read the same comparison. A scheduler that stops running therefore costs the company
 * its warnings and can never make a lapsed insurance certificate look valid — which is the failure
 * mode that actually matters. Contracts, vendor records, training records and risk assessments work
 * the same way, for the same reason.
 *
 * A DOCUMENT WITH NO EXPIRY NEVER EXPIRES, deliberately. Proof of ownership does not lapse, and a
 * null `expires_on` means exactly that rather than "unknown" — see `VehicleDocument::hasExpired()`.
 *
 * FILES GO TO PRIVATE STORAGE (§67). A certificate scan carries the plate, the owner's name and
 * often an address; it is never web-reachable, and every download re-runs the policy against the
 * record it claims to belong to rather than trusting the path (§84).
 */
class VehicleDocumentService
{
    /** Certificates are scans and PDFs. Nothing here is ever a document somebody edits. */
    public const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
    ];

    public const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly OptionList $options,
    ) {}

    /**
     * File a certificate against a vehicle.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function create(Vehicle $vehicle, array $attributes, User $actor, ?UploadedFile $file = null): VehicleDocument
    {
        return DB::transaction(function () use ($vehicle, $attributes, $actor, $file) {
            $document = new VehicleDocument([
                'document_number' => $attributes['document_number'] ?? null,
                'issuer' => $attributes['issuer'] ?? null,
                'issued_on' => $attributes['issued_on'] ?? null,
                'expires_on' => $attributes['expires_on'] ?? null,
                'cost' => $attributes['cost'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                // Defaulted rather than required, because a seeder and an import file certificates
                // too and neither should have to think about it. `internal` is the safe default: a
                // scan reaches the people who can already open the vehicle and nobody wider.
                'confidentiality' => $attributes['confidentiality'] ?? Confidentiality::Internal->value,
            ]);

            $document->organization_id = $vehicle->organization_id;
            $document->vehicle_id = $vehicle->getKey();
            $document->document_type = $this->type($attributes['document_type'] ?? null, $actor);
            $document->created_by = $actor->getKey();
            $document->save();

            if ($file !== null) {
                $this->attachFile($document, $file, $actor);
            }

            $this->activity->record(
                $document,
                'created',
                $actor->name.' filed a '.mb_strtolower($document->typeLabel()).' for '
                    .$vehicle->registration_number.'.',
                array_filter(['expires_on' => $document->expires_on?->toDateString()]),
                $actor,
            );

            return $document;
        });
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(VehicleDocument $document, array $attributes, User $actor, ?UploadedFile $file = null): VehicleDocument
    {
        if ($document->superseded_on !== null) {
            throw new \DomainException('A renewed certificate is historical evidence and cannot be changed.');
        }
        if ($document->renews_id !== null) {
            // These determine the predecessor's effective replacement date and identity.
            unset($attributes['issued_on'], $attributes['document_type']);
        }
        $document->fill(array_intersect_key($attributes, array_flip([
            'document_number', 'issuer', 'issued_on', 'expires_on', 'cost', 'notes', 'confidentiality',
        ])));

        if (array_key_exists('document_type', $attributes)) {
            $document->document_type = $this->type($attributes['document_type'], $actor);
        }

        $changed = array_keys($document->getDirty());

        if ($changed !== []) {
            $document->save();

            $this->activity->record(
                $document,
                'updated',
                $actor->name.' updated the '.mb_strtolower($document->typeLabel()).' record.',
                ['fields' => $changed],
                $actor,
            );
        }

        if ($file !== null) {
            $this->attachFile($document, $file, $actor);
        }

        return $document;
    }

    /**
     * File next year's certificate, retaining the old details and linking its replacement.
     *
     * The new row inherits type, issuer and vehicle, because a renewal is the same paperwork from
     * the same authority — and inheriting them means the person renewing forty vehicles types the
     * two things that actually changed. What it does NOT inherit is the number or the file: a new
     * certificate has its own number and its own scan, and copying either would be a forgery in a
     * register somebody may one day rely on.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function renew(VehicleDocument $document, array $attributes, User $actor, ?UploadedFile $file = null): VehicleDocument
    {
        return DB::transaction(function () use ($document, $attributes, $actor, $file) {
            VehicleDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
            $document->refresh();

            if ($document->superseded_on !== null) {
                throw new \DomainException('This certificate has already been renewed. Open the replacement to renew it again.');
            }

            $renewal = $this->fileRenewal($document, $attributes, $actor, $file);
            $renewal->forceFill(['renews_id' => $document->getKey()])->save();
            $document->forceFill(['superseded_on' => $renewal->issued_on ?? now()->startOfDay()])->save();

            return $renewal;
        });
    }

    private function fileRenewal(VehicleDocument $document, array $attributes, User $actor, ?UploadedFile $file): VehicleDocument
    {
        $document->loadMissing('vehicle');

        if ($document->vehicle === null) {
            throw new \DomainException('That document is not attached to a vehicle.');
        }

        $classification = $attributes['confidentiality'] ?? $document->confidentiality;
        $classification = $classification instanceof Confidentiality
            ? $classification : Confidentiality::from($classification);
        if ($classification->rank() < $document->confidentiality->rank()) {
            $classification = $document->confidentiality;
        }

        $renewal = $this->create($document->vehicle, array_merge($attributes, [
            'document_type' => $document->document_type,
            'issuer' => $attributes['issuer'] ?? $document->issuer,
            'confidentiality' => $classification,
            // The old expiry is the natural start of the new cover, and a renewal filed a fortnight
            // early should not create a gap in the record.
            'issued_on' => $attributes['issued_on'] ?? $document->expires_on?->toDateString(),
        ]), $actor, $file);

        $this->activity->record(
            $document,
            'renewed',
            $actor->name.' renewed this '.mb_strtolower($document->typeLabel())
                .($renewal->expires_on !== null ? ' to '.$renewal->expires_on->toDateString() : '').'.',
            ['replacement_id' => $renewal->getKey()],
            $actor,
        );

        return $renewal;
    }

    // ================================================================== files

    /**
     * Put the scan in private storage, having checked what it actually is.
     *
     * VALIDATED BY MIME AND EXTENSION AND SIZE, all three (§67). The extension is what a web server
     * would dispatch on and the MIME is what the file claims to be; a `.php` renamed to `.pdf`
     * fails the first check and a PDF renamed to `.php` fails the second, and neither ever lands in
     * a directory anything can execute from.
     *
     * THE STORED NAME IS GENERATED, never the uploaded one. A filename is attacker-controlled text
     * that ends up in a path, a header and a log line; the original is kept in a column, where it is
     * data rather than a route.
     */
    public function attachFile(VehicleDocument $document, UploadedFile $file, User $actor): VehicleDocument
    {
        $mime = (string) $file->getMimeType();
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new \DomainException(
                'A vehicle document must be a PDF or a photograph of the certificate. '
                .'That file appears to be '.$mime.'.'
            );
        }

        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'], true)) {
            throw new \DomainException('That file type cannot be attached to a vehicle document.');
        }

        if ($file->getSize() > self::MAX_FILE_BYTES) {
            throw new \DomainException('A vehicle document may be at most 10MB.');
        }

        $path = $file->storeAs(
            'organizations/'.$document->organization_id.'/vehicle-documents/'.$document->vehicle_id,
            Str::uuid()->toString().'.'.$extension,
            'local',
        );

        $previous = $document->file_path;

        $document->forceFill([
            'file_path' => $path,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_mime' => $mime,
            'file_size' => $file->getSize(),
        ])->save();

        // Replaced, not orphaned. The row now points at the new scan and the old one has no reader
        // left, so leaving it on disk is somebody's insurance certificate sitting in storage for
        // ever with nothing referencing it.
        if (filled($previous)) {
            Storage::disk('local')->delete($previous);
        }

        $this->activity->record(
            $document,
            'file_attached',
            $actor->name.' attached a scan of the '.mb_strtolower($document->typeLabel()).'.',
            ['file_name' => $document->file_name],
            $actor,
        );

        return $document;
    }

    // ================================================================== the sweep

    /**
     * Warn about certificates lapsing inside the company's window. Called by the scheduler.
     *
     * RAISES EVENTS AND WRITES NOTHING — see the class docblock. Documents already expired are
     * included, with a negative day count, because a lapsed insurance certificate is more urgent
     * than one lapsing next month and stopping the warnings at the expiry date is exactly when the
     * company most needs to hear about it.
     *
     * @return int how many warnings were raised
     */
    public function sweepExpiring(int $organizationId): int
    {
        $days = $this->settings->int('vehicle_document.expiry_warning_days', 60, $organizationId);

        $today = Carbon::today();
        $horizon = $today->copy()->addDays($days);

        $documents = VehicleDocument::query()
            ->current()
            ->where('organization_id', $organizationId)
            ->with('vehicle.asset')
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', $horizon->toDateString())
            ->get();

        foreach ($documents as $document) {
            VehicleDocumentExpiring::dispatch(
                $document,
                (int) $today->diffInDays(Carbon::parse($document->expires_on), false),
            );
        }

        return $documents->count();
    }

    // ================================================================== helpers

    /**
     * Always one of the company's own words (§84), and never null.
     *
     * Unlike an asset category, an uncategorised document is useless: "the thing that expires in
     * March" is not an answer anybody can act on, and the whole register is organised by type. A
     * value outside the list falls back to the first configured type rather than to null, so a
     * crafted payload produces a mislabelled record rather than an unusable one.
     */
    private function type(mixed $value, User $actor): string
    {
        $allowed = $this->options->keys(
            'vehicle_document.types',
            VehicleDocument::DEFAULT_TYPES,
            $actor->organization_id,
        );

        $slug = blank($value) ? null : OptionList::slug((string) $value);

        if ($slug !== null && in_array($slug, $allowed, true)) {
            return $slug;
        }

        return $allowed[0] ?? OptionList::slug(VehicleDocument::DEFAULT_TYPES[0]);
    }
}
