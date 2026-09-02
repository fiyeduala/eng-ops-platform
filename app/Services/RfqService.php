<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\RfqAwarded;
use App\Events\RfqIssued;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqVendor;
use App\Models\Status;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Concerns\ManagesLineItems;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The enquiry: what we want, who we asked, and who won.
 *
 * THE LINES ARE COPIED FROM THE REQUISITION, NOT READ THROUGH IT. `fromPurchaseRequest()` writes
 * real rows onto the enquiry and keeps a pointer back to the requisition line for traceability. The
 * alternative — an enquiry that renders its parent's lines live — means editing a requisition six
 * weeks later silently changes what four suppliers were asked to quote, and their quotations then
 * answer a question nobody can reconstruct.
 *
 * INVITING IS NOT ISSUING. A buyer builds the list over days and sends it once. `invite()` adds a
 * supplier to the list; `issue()` is the moment the enquiry leaves the building, and it stamps
 * `invited_at` on everybody who was on the list at that moment. Suppliers added after issue are
 * stamped when they are added, because for them that IS the moment.
 *
 * THE AWARD IS ONE ACT ON EVERY QUOTATION AT ONCE. `award()` accepts the winner and marks every
 * other live quotation unsuccessful inside one transaction, which is why no quotation has a clickable
 * "accept" chip. Two half-awards — a winner with no losers, or two winners — are states a
 * procurement file cannot be audited out of, and the only reliable way to prevent them is to make
 * the whole decision a single statement.
 *
 * COMPETITION IS A COMPANY'S POLICY, NOT OURS. `procurement.minimum_quotations` says how many
 * responses this company expects before awarding. Below that the award is not refused — a genuine
 * sole-source exists on every project — it requires a written reason, which is the control an
 * auditor actually asks for.
 */
class RfqService extends RegisterService
{
    use ManagesLineItems;

    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'rfq';
    }

    protected function modelClass(): string
    {
        return Rfq::class;
    }

    /** @return class-string<Model> */
    protected function itemClass(): string
    {
        return RfqItem::class;
    }

    protected function itemForeignKey(): string
    {
        return 'rfq_id';
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id',
            'issued_by', 'issued_at',
            'awarded_quotation_id', 'awarded_by', 'awarded_at', 'award_reason',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Rfq $record */
        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor, record: false);
        }
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Rfq $record */
        $record = parent::update($record, $attributes, $actor);

        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor);
        }

        return $record;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function itemAttributes(array $row, Model $parent): array
    {
        return [
            'purchase_request_item_id' => $this->parentLineId($row, $parent),
            'description' => (string) $row['description'],
            'specification' => blank($row['specification'] ?? null) ? null : (string) $row['specification'],
            'quantity' => (float) ($row['quantity'] ?? 0),
            'uom' => blank($row['uom'] ?? null) ? null : (string) $row['uom'],
            'notes' => blank($row['notes'] ?? null) ? null : (string) $row['notes'],
        ];
    }

    /**
     * A posted requisition-line id is only accepted when it belongs to THIS enquiry's requisition.
     *
     * Otherwise the traceability pointer becomes a way to attach an enquiry line to a line on a
     * requisition in another project — or another tenant — and the audit trail reads as though a
     * requisition somebody cannot open asked for this (§75, §84).
     *
     * @param  array<string,mixed>  $row
     */
    private function parentLineId(array $row, Model $parent): ?int
    {
        /** @var Rfq $parent */
        $id = isset($row['purchase_request_item_id']) ? (int) $row['purchase_request_item_id'] : 0;

        if ($id <= 0 || $parent->purchase_request_id === null) {
            return null;
        }

        return $parent->purchaseRequest?->items()->whereKey($id)->exists() ? $id : null;
    }

    // ================================================================== from a requisition

    /**
     * Raise an enquiry for an approved requisition, carrying its lines across.
     *
     * @param  array<string,mixed>  $attributes  the enquiry's own header — title, scope, closing date
     */
    public function fromPurchaseRequest(PurchaseRequest $request, array $attributes, User $actor): Rfq
    {
        if (! $request->isOrderable()) {
            throw new \DomainException('Only an approved purchase request can go out to enquiry.');
        }

        return DB::transaction(function () use ($request, $attributes, $actor) {
            $lines = $request->items()->orderBy('line_no')->get()->map(fn ($item) => [
                'purchase_request_item_id' => $item->getKey(),
                'description' => $item->description,
                'specification' => $item->specification,
                'quantity' => $item->quantity,
                'uom' => $item->uom,
            ])->all();

            /** @var Rfq $rfq */
            $rfq = $this->create([
                // The requisition's own headings travel with it so the buyer does not retype them,
                // and every one of them is overridable by the form.
                'title' => $attributes['title'] ?? $request->title,
                'scope' => $attributes['scope'] ?? $request->justification,
                'project_id' => $attributes['project_id'] ?? $request->project_id,
                'org_unit_id' => $attributes['org_unit_id'] ?? $request->org_unit_id,
                ...$attributes,
                'purchase_request_id' => $request->getKey(),
                'items' => $lines,
            ], $actor);

            $this->activity->record(
                $request,
                'enquiry_raised',
                $actor->name.' raised enquiry '.$rfq->number.' from '.$this->label($request).'.',
                ['rfq' => $rfq->number, 'items' => count($lines)],
                $actor,
                $this->projectIdOf($request),
            );

            return $rfq;
        });
    }

    // ================================================================== the invitation list

    /**
     * Put a supplier on the list.
     *
     * A supplier who cannot be used is refused with the reason, not silently skipped — a buyer who
     * cannot see why a name did not appear will assume the system lost it. What counts as unusable
     * is Vendor::canBeUsed(), which is one conjunction in one place; note that a supplier nobody
     * has ever prequalified is usable by design, because a company that does not prequalify still
     * has to be able to buy things.
     */
    public function invite(Rfq $rfq, Vendor $vendor, User $actor, ?string $notes = null): RfqVendor
    {
        if ($rfq->isAwarded() || $rfq->isClosed()) {
            throw new \DomainException('This enquiry has been decided; suppliers can no longer be added.');
        }

        if (! $vendor->canBeUsed()) {
            throw new \DomainException($vendor->name.' cannot be invited: '.strtolower((string) $vendor->unusableReason()).'.');
        }

        return DB::transaction(function () use ($rfq, $vendor, $actor, $notes) {
            /** @var RfqVendor|null $existing */
            $existing = $rfq->invitations()->where('vendor_id', $vendor->getKey())->first();

            if ($existing !== null) {
                return $existing;
            }

            $invitation = new RfqVendor;
            $invitation->forceFill([
                'organization_id' => $rfq->organization_id,
                'rfq_id' => $rfq->getKey(),
                'vendor_id' => $vendor->getKey(),
                // Already issued, so this supplier's invitation IS this moment. See the docblock.
                'invited_at' => $rfq->issued_at !== null ? now() : null,
                'notes' => $notes,
            ]);
            $invitation->save();

            $rfq->unsetRelation('invitations');

            $this->activity->record(
                $rfq,
                'supplier_invited',
                $actor->name.' invited '.$vendor->name.' to '.$this->label($rfq).'.',
                ['vendor' => $vendor->name],
                $actor,
                $this->projectIdOf($rfq),
            );

            return $invitation;
        });
    }

    /**
     * Take a supplier off the list — only while nothing has come back from them.
     *
     * Removing an invitation a quotation was received against would leave the quotation pointing at
     * an enquiry that never asked them, which is the version of this record an auditor reads.
     */
    public function uninvite(RfqVendor $invitation, User $actor): void
    {
        if ($invitation->hasResponded()) {
            throw new \DomainException('That supplier has already quoted, so their invitation stands.');
        }

        DB::transaction(function () use ($invitation, $actor) {
            $rfq = $invitation->rfq;
            $name = $invitation->vendor?->name ?? 'A supplier';

            $invitation->delete();

            if ($rfq !== null) {
                $rfq->unsetRelation('invitations');

                $this->activity->record(
                    $rfq,
                    'supplier_removed',
                    $actor->name.' removed '.$name.' from '.$this->label($rfq).'.',
                    ['vendor' => $name],
                    $actor,
                    $this->projectIdOf($rfq),
                );
            }
        });
    }

    /**
     * They told us they are not quoting. Worth recording: a silent supplier and one who declined
     * are different facts, and only one of them is a reason to look for another supplier.
     */
    public function recordDecline(RfqVendor $invitation, User $actor, ?string $reason = null): RfqVendor
    {
        if ($invitation->hasResponded()) {
            throw new \DomainException('A supplier who has quoted cannot also have declined.');
        }

        $invitation->forceFill(['declined_at' => now(), 'decline_reason' => $reason])->save();

        $rfq = $invitation->rfq;

        if ($rfq !== null) {
            $this->activity->record(
                $rfq,
                'supplier_declined',
                ($invitation->vendor?->name ?? 'A supplier').' declined to quote on '.$this->label($rfq).'.',
                array_filter(['vendor' => $invitation->vendor?->name, 'reason' => $reason]),
                $actor,
                $this->projectIdOf($rfq),
            );
        }

        return $invitation;
    }

    // ================================================================== issuing and awarding

    /**
     * Send it out. This is the moment the deadline starts meaning something.
     */
    public function issue(Rfq $rfq, User $actor): Rfq
    {
        if ($rfq->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft enquiry can be issued.');
        }

        if ($rfq->items()->doesntExist()) {
            throw new \DomainException('An enquiry cannot be issued with nothing on it.');
        }

        if ($rfq->invitations()->doesntExist()) {
            throw new \DomainException('An enquiry cannot be issued without at least one supplier on the list.');
        }

        return DB::transaction(function () use ($rfq, $actor) {
            $rfq->forceFill([
                'issued_by' => $rfq->issued_by ?? $actor->getKey(),
                'issued_at' => now(),
            ]);

            $this->moveTo($rfq, 'issued');
            $rfq->save();

            // Everybody on the list was invited at this moment — see the class docblock.
            $rfq->invitations()->whereNull('invited_at')->update(['invited_at' => now()]);
            $rfq->unsetRelation('invitations');

            $this->activity->record(
                $rfq,
                'issued',
                $actor->name.' issued '.$this->label($rfq).' to '.$rfq->invitations()->count().' supplier(s).',
                ['suppliers' => $rfq->invitations()->count()],
                $actor,
                $this->projectIdOf($rfq),
            );

            RfqIssued::dispatch($rfq, (int) $actor->getKey());

            return $rfq;
        });
    }

    /**
     * Decide it — the winner and the losers in one statement.
     *
     * @param  string|null  $reason  why this one. Required when fewer suppliers responded than the
     *                               company expects, which is the case an auditor asks about.
     */
    public function award(Rfq $rfq, Quotation $quotation, User $actor, ?string $reason = null): Rfq
    {
        if ($rfq->isAwarded()) {
            throw new \DomainException('This enquiry has already been awarded.');
        }

        // The quotation must be answering THIS enquiry. Without this a posted quotation id awards
        // an enquiry to a price quoted for something else entirely (§84).
        if ((int) $quotation->rfq_id !== (int) $rfq->getKey()) {
            throw new \DomainException('That quotation was not received against this enquiry.');
        }

        if ($quotation->statusKey() === 'withdrawn') {
            throw new \DomainException('That quotation has been withdrawn by the supplier.');
        }

        $received = $rfq->quotations()->count();
        $minimum = $this->settings->int('procurement.minimum_quotations', 3, $rfq->organization_id);

        if ($received < $minimum && blank($reason)) {
            throw new \DomainException(
                'Your organization expects '.$minimum.' quotations before an award and '.$received
                    .' were received, so this award needs a written reason.'
            );
        }

        return DB::transaction(function () use ($rfq, $quotation, $actor, $reason, $received, $minimum) {
            $accepted = $this->statusFor('quotation', 'accepted');
            $unsuccessful = $this->statusIdFor('quotation', 'unsuccessful');
            $withdrawn = $this->statusIdFor('quotation', 'withdrawn');

            if ($accepted !== null) {
                $quotation->forceFill(['status_id' => $accepted->getKey()])->save();
                // The winning quotation is very often handed straight to PurchaseOrderService by
                // the same caller, and that service asks the RECORD whether it won. Leaving the
                // loaded relation on 'received' would make the order refuse the award we just made.
                $quotation->setRelation('status', $accepted);
            }

            if ($unsuccessful !== null) {
                // Everybody else who is still live. A withdrawn quotation is left alone: the
                // supplier's own statement about their offer is not ours to overwrite.
                $rfq->quotations()
                    ->whereKeyNot($quotation->getKey())
                    ->when($withdrawn !== null, fn ($query) => $query->where('status_id', '!=', $withdrawn))
                    ->update(['status_id' => $unsuccessful]);
            }

            $rfq->forceFill([
                'awarded_quotation_id' => $quotation->getKey(),
                'awarded_by' => $actor->getKey(),
                'awarded_at' => now(),
                'award_reason' => $reason,
            ]);

            $this->moveTo($rfq, 'awarded');
            $rfq->save();

            $rfq->unsetRelation('quotations');

            $this->activity->record(
                $rfq,
                'awarded',
                $actor->name.' awarded '.$this->label($rfq).' to '
                    .($quotation->vendor?->name ?? 'the selected supplier').'.',
                array_filter([
                    'quotation' => $quotation->number,
                    'vendor' => $quotation->vendor?->name,
                    'reason' => $reason,
                    // Recorded on the enquiry rather than left to be inferred from a count that
                    // changes as quotations are archived.
                    'quotations_received' => $received,
                    'minimum_expected' => $minimum,
                ]),
                $actor,
                $this->projectIdOf($rfq),
            );

            RfqAwarded::dispatch($rfq, $quotation, (int) $actor->getKey());

            return $rfq;
        });
    }

    /**
     * A status id in ANOTHER register's vocabulary.
     *
     * `moveTo()` only knows this service's own entity type, and the award writes quotation statuses
     * — which is the one legitimate cross-register status write in the chain, because the winner
     * and the caller then leaves the quotation where it is rather than guessing.
     */
    private function statusIdFor(string $entityType, string $key): ?int
    {
        $status = $this->statusFor($entityType, $key);

        return $status === null ? null : (int) $status->getKey();
    }

    /** The row itself, so the caller can keep a loaded `status` relation honest. */
    private function statusFor(string $entityType, string $key): ?Status
    {
        return $this->statuses->statusesFor($entityType)->firstWhere('key', $key);
    }
}
