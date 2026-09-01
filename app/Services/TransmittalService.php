<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\StatusMachine;
use App\Engines\Document\PdfRenderer;
use App\Events\TransmittalIssued;
use App\Models\DocumentVersion;
use App\Models\Transmittal;
use App\Models\TransmittalItem;
use App\Models\User;
use App\Services\Concerns\ResolvesParties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Formal issue of documents to somebody outside the team (§28).
 *
 * A transmittal is a LEGAL RECORD, and every design decision here follows from that one sentence.
 * It answers "what exactly did we send, to whom, when, and did they confirm receipt" — the question
 * that decides who pays when a contractor builds to a superseded drawing.
 *
 * WHY ITEMS POINT AT A VERSION, NOT A DOCUMENT. This is the single most important thing in the file.
 * "We sent you A-101" is worthless a year later; "we sent you A-101 Rev C on 14 March" is the whole
 * point. The foreign key is `document_version_id` with restrictOnDelete, so the revision that was
 * transmitted physically cannot be deleted while a transmittal references it.
 *
 * WHY ISSUING IS ONE-WAY. Draft is editable; issued is not. A transmittal that could be edited after
 * it went out is a record that proves nothing, because the copy in the file no longer has to match
 * the copy the recipient received.
 */
class TransmittalService
{
    use ResolvesParties;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StatusMachine $statuses,
        private readonly ActivityRecorder $activity,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     */
    public function create(array $attributes, User $actor): Transmittal
    {
        return DB::transaction(function () use ($attributes, $actor) {
            $transmittal = new Transmittal($this->safeAttributes($attributes));

            $transmittal->organization_id = $actor->organization_id;
            $transmittal->created_by = $actor->getKey();
            $transmittal->number = $this->numbers->next('transmittal');
            $transmittal->status_id = $this->statuses->initialFor('transmittal')?->id;

            // The recipient may be a client, a vendor or another unit of this company — a drawing
            // issued from engineering to construction is a transmittal in every organization that
            // issues them. The id is resolved through a tenant-scoped lookup rather than filled,
            // so a posted id belonging to somebody else's client simply does not resolve (§75).
            $this->nameParty(
                $transmittal,
                $this->resolveParty($transmittal, $attributes, 'to_party_type', 'to_party_id', 'toParty'),
                'to_name',
                $attributes,
            );

            $transmittal->save();

            $this->activity->record(
                $transmittal,
                'created',
                $actor->name.' started transmittal '.$transmittal->number.' to '.$transmittal->to_name.'.',
                ['number' => $transmittal->number],
                $actor,
                $transmittal->project_id !== null ? (int) $transmittal->project_id : null,
            );

            return $transmittal;
        });
    }

    /** @param array<string,mixed> $attributes */
    public function update(Transmittal $transmittal, array $attributes, User $actor): Transmittal
    {
        $this->assertDraft($transmittal, 'changed');

        $transmittal->fill($this->safeAttributes($attributes));

        $this->nameParty(
            $transmittal,
            $this->resolveParty($transmittal, $attributes, 'to_party_type', 'to_party_id', 'toParty'),
            'to_name',
            $attributes,
        );

        $transmittal->save();

        return $transmittal;
    }

    // ================================================================== contents

    /**
     * Put a revision on the transmittal.
     *
     * The revision must belong to the same tenant, checked explicitly rather than trusted to the
     * global scope. The scope protects queries; this protects against a caller that already holds a
     * model — and an id posted in a form is exactly that case (§84, §75).
     *
     * @param  array<string,mixed>  $attributes
     */
    public function addItem(
        Transmittal $transmittal,
        DocumentVersion $version,
        array $attributes,
        User $actor,
    ): TransmittalItem {
        $this->assertDraft($transmittal, 'added to');

        if ((int) $version->organization_id !== (int) $transmittal->organization_id) {
            throw new \DomainException('That revision does not belong to this organization.');
        }

        return DB::transaction(function () use ($transmittal, $version, $attributes, $actor) {
            $item = new TransmittalItem([
                'copies' => max(1, (int) ($attributes['copies'] ?? 1)),
                'media' => $attributes['media'] ?? 'electronic',
                'remarks' => $attributes['remarks'] ?? null,
                'sort' => (int) ($attributes['sort'] ?? ($transmittal->items()->max('sort') + 1)),
            ]);

            $item->organization_id = $transmittal->organization_id;
            $item->transmittal_id = $transmittal->getKey();
            $item->document_version_id = $version->getKey();
            $item->created_by = $actor->getKey();

            $item->save();

            return $item;
        });
    }

    public function removeItem(TransmittalItem $item, User $actor): void
    {
        $this->assertDraft($item->transmittal, 'changed');

        $item->delete();
    }

    // ================================================================== issuing

    /**
     * Send it. The one-way door.
     *
     * `issued_at` is the publication column the access engine reads: until it is set, only the
     * people who can see drafts can see the transmittal at all. Setting it is therefore both "this
     * went out" and "this is now visible to the wider audience", which is exactly right — those two
     * facts became true at the same moment.
     *
     * @throws \DomainException when there is nothing to send
     */
    public function issue(Transmittal $transmittal, User $actor): Transmittal
    {
        $this->assertDraft($transmittal, 'issued');

        if ($transmittal->items()->doesntExist()) {
            throw new \DomainException('A transmittal cannot be issued with no documents on it.');
        }

        return DB::transaction(function () use ($transmittal, $actor) {
            $issued = $this->statuses->statusesFor('transmittal')->firstWhere('key', 'issued');

            $transmittal->forceFill([
                'issued_at' => now(),
                'issued_by' => $actor->getKey(),
                'status_id' => $issued?->id ?? $transmittal->status_id,
            ])->save();

            $this->activity->record(
                $transmittal,
                'issued',
                $actor->name.' issued '.$transmittal->number.' to '.$transmittal->to_name
                    .' ('.$transmittal->items()->count().' document(s)).',
                [
                    'number' => $transmittal->number,
                    'to' => $transmittal->to_name,
                    'items' => $transmittal->items()->count(),
                ],
                $actor,
                $transmittal->project_id !== null ? (int) $transmittal->project_id : null,
            );

            TransmittalIssued::dispatch($transmittal, (int) $actor->getKey());

            return $transmittal;
        });
    }

    /**
     * Record that the recipient confirmed receipt.
     *
     * The acknowledger's NAME is a free-text snapshot rather than a user id, because the person
     * acknowledging is usually outside this system entirely — a client's document controller who
     * replied to an email. Modelling them as a user would either be a lie or require creating
     * accounts for people who will never log in.
     */
    public function acknowledge(
        Transmittal $transmittal,
        User $actor,
        ?string $byName = null,
        ?string $reference = null,
    ): Transmittal {
        if (! $transmittal->isIssued()) {
            throw new \DomainException('A transmittal cannot be acknowledged before it has been issued.');
        }

        if ($transmittal->isAcknowledged()) {
            return $transmittal;
        }

        $acknowledged = $this->statuses->statusesFor('transmittal')->firstWhere('key', 'acknowledged');

        $transmittal->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by_name' => $byName !== null ? mb_substr($byName, 0, 200) : $transmittal->to_name,
            'acknowledgement_reference' => $reference !== null ? mb_substr($reference, 0, 200) : null,
            'status_id' => $acknowledged?->id ?? $transmittal->status_id,
        ])->save();

        $this->activity->record(
            $transmittal,
            'acknowledged',
            'Receipt of '.$transmittal->number.' was acknowledged by '.$transmittal->acknowledged_by_name.'.',
            array_filter(['reference' => $reference]),
            $actor,
        );

        return $transmittal;
    }

    public function cancel(Transmittal $transmittal, User $actor, string $reason): Transmittal
    {
        $cancelled = $this->statuses->statusesFor('transmittal')->firstWhere('key', 'cancelled');

        $transmittal->forceFill(['status_id' => $cancelled?->id ?? $transmittal->status_id])->save();

        $this->activity->record(
            $transmittal,
            'cancelled',
            $actor->name.' cancelled '.$transmittal->number.'. '.$reason,
            ['reason' => $reason],
            $actor,
        );

        return $transmittal;
    }

    // ================================================================== the cover sheet

    /**
     * The PDF that goes out with the documents.
     *
     * Rendered on demand rather than stored, so it always reflects the record — and because storing
     * it would create a second artefact that could drift from the row it describes. The transmittal
     * is immutable once issued, so "rendered now" and "rendered then" are the same document anyway.
     */
    public function coverSheet(Transmittal $transmittal): string
    {
        // `creator` as well as `issuer`: a draft cover sheet has no issuer yet, and the sheet still
        // has to say whose desk it came from. Lazy loading is prevented outside production, so a
        // relation the view reaches for and this line forgot is an exception, not a slow query.
        $transmittal->loadMissing([
            'items.documentVersion.document', 'project', 'issuer', 'creator', 'organization',
        ]);

        return $this->pdf->renderHtml(
            title: 'Transmittal '.$transmittal->number,
            bodyHtml: view('transmittals.pdf', ['transmittal' => $transmittal])->render(),
            headerHtml: null,
            footerHtml: null,
            template: null,
            watermark: $transmittal->isIssued() ? null : 'DRAFT',
        );
    }

    // ================================================================== internals

    private function assertDraft(Transmittal $transmittal, string $verb): void
    {
        if ($transmittal->isIssued()) {
            throw new \DomainException(
                'Transmittal '.$transmittal->number.' was issued on '
                .$transmittal->issued_at?->format('j M Y').' and cannot be '.$verb.'.'
            );
        }
    }

    /**
     * The one register that may also be addressed internally.
     *
     * A transmittal from the design office to the construction team is a formal issue with the same
     * evidential weight as one to a client — "we sent you Rev C on 14 March" is the point, and who
     * "you" was does not change what the record has to prove.
     *
     * @return array<string,class-string<Model>>
     */
    protected function partyClasses(): array
    {
        return $this->partyClassesWithUnits();
    }

    protected function partyTypeMessage(): string
    {
        return 'A transmittal is addressed to a client, a vendor or a unit of this company.';
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     */
    private function safeAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip([
            'id', 'organization_id', 'number', 'created_by', 'status_id',
            'issued_at', 'issued_by', 'acknowledged_at', 'acknowledged_by_name',
            'created_at', 'updated_at', 'deleted_at',
            // The morph pair is resolved through ResolvesParties, never filled — see that trait.
            'to_party_type', 'to_party_id',
        ]));
    }
}
