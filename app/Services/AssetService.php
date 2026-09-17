<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\AssetCustodyTransferred;
use App\Models\Asset;
use App\Models\AssetCustodyTransfer;
use App\Models\GoodsReceiptItem;
use App\Models\User;
use App\Support\FixedRateMoney;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The asset register, and the one door through which custody may change.
 *
 * THE CUSTODY CACHE IS NOT THE TRUTH. `assets.custodian_id`, `org_unit_id`, `project_id` and
 * `location_id` are four columns that say where a thing is NOW; `asset_custody_transfers` is the
 * append-only ledger that says how it got there, and the ledger is the record of account. The two
 * are written together in one transaction by `transferCustody()` and by nothing else, exactly as
 * `stock_balances` and `stock_transactions` are written together by StockService::post() — same
 * shape, same reason, and `Asset::custodyMatchesTrail()` exists so a test can assert the claim
 * rather than the codebase merely making it.
 *
 * WHICH IS WHY `transfer` IS NOT `edit` IN THE PERMISSION CATALOGUE. If the four cache columns were
 * reachable through an ordinary update, anybody holding `asset.edit` could move a £40,000 machine
 * onto their own name with no ledger row behind it, and the register would agree with them. The
 * service enforces that structurally: `protectedAttributes()` strips all four from every payload
 * `create()` and `update()` will ever see, so there is no form field, crafted or otherwise, that
 * reaches them (§84).
 *
 * `retire()` RATHER THAN DELETE, and the policy says the same thing from the other side. A machine
 * that has been in somebody's hands has a history that must outlive it: who had it, what we paid,
 * what it cost to keep running, and what happened to it at the end. Disposal is that record's last
 * chapter. The row typed in error an hour ago, with no trail behind it, is still deletable — see
 * AssetPolicy::delete().
 *
 * CUSTODY IS RETURNED, NOT CLEARED, ON RETIREMENT. A retired asset is handed back to nobody, and
 * that handover is written as an ordinary trail row with four nulls on the `to_` side. Nulling the
 * cache without the row would produce exactly the silent history rewrite this class exists to
 * prevent.
 */
class AssetService extends RegisterService
{
    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly OptionList $options,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'asset';
    }

    protected function modelClass(): string
    {
        return Asset::class;
    }

    /**
     * The code is typed, not generated — see NumberGenerator::defaults().
     *
     * A plant manager calls the 250kVA set GEN-02 because that is what is stencilled on its side,
     * and a generated ASS-2026-0001 beside it would be a second identifier nobody says out loud.
     */
    protected function numberColumn(): ?string
    {
        return null;
    }

    /**
     * The four custody columns are the reason this list is not just `status_id`.
     *
     * They are a cache of the ledger's head. A payload that could set them would be a payload that
     * could move an asset without leaving a trail — which is the one thing this module must make
     * impossible. `is_vehicle` joins them because flipping it on an asset with a `vehicles` row
     * already attached would orphan the plate, the odometer and every fuel record hanging off it.
     * `purchase_cost` and `goods_receipt_item_id` are stamped from the delivery by
     * `registerFromReceipt()` and are statements about what actually happened.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'custodian_id', 'org_unit_id', 'project_id', 'location_id',
            'is_vehicle', 'goods_receipt_item_id', 'disposed_on', 'disposal_reference',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Asset $record */
        $record->code = $this->normaliseCode($record->code);
        $record->category = $this->category($attributes['category'] ?? null, $actor);

        // Set here rather than left to the payload, because it decides whether a `vehicles` row may
        // exist at all. FleetService is the only thing that turns it on, and it does so on the way
        // to creating that row.
        $record->is_vehicle = false;
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Asset $record */
        return $actor->name.' added '.$record->displayName().' to the asset register.';
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        if (array_key_exists('code', $attributes)) {
            $attributes['code'] = $this->normaliseCode((string) $attributes['code']);
        }

        if (array_key_exists('category', $attributes)) {
            $attributes['category'] = $this->category($attributes['category'], $actor);
        }

        return parent::update($record, $attributes, $actor);
    }

    // ================================================================== custody

    /**
     * Move an asset from whoever has it to whoever is taking it. THE ONLY WAY CUSTODY CHANGES.
     *
     * Writes the ledger row and updates the cache inside one transaction, in that order, so a
     * failure anywhere leaves both untouched. The `from_` side is read from the asset's current
     * cache rather than from the caller: whoever is submitting the form does not get to say who
     * they are taking it from (§84).
     *
     * A HANDOVER TO NOBODY IS LEGITIMATE and is how an asset comes back to the store — all four
     * `to_` columns null. A handover that changes nothing is not, and is refused rather than
     * written, because a trail full of no-op rows is a trail nobody reads.
     *
     * @param  array{custodian_id?:int|null,org_unit_id?:int|null,project_id?:int|null,location_id?:int|null}  $to
     */
    public function transferCustody(
        Asset $asset,
        array $to,
        User $actor,
        ?string $reason = null,
        ?string $condition = null,
        ?string $remarks = null,
        ?int $authorisedBy = null,
    ): AssetCustodyTransfer {
        return DB::transaction(function () use ($asset, $to, $actor, $reason, $condition, $remarks, $authorisedBy) {
            Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();
            $asset->refresh();

            return $this->transferLocked($asset, $to, $actor, $reason, $condition, $remarks, $authorisedBy);
        });
    }

    private function transferLocked(
        Asset $asset,
        array $to,
        User $actor,
        ?string $reason = null,
        ?string $condition = null,
        ?string $remarks = null,
        ?int $authorisedBy = null,
    ): AssetCustodyTransfer {
        if (($refusal = $asset->custodyRefusalReason()) !== null) {
            throw new \DomainException($refusal);
        }

        $target = [
            'custodian_id' => $this->intOrNull($to['custodian_id'] ?? null),
            'org_unit_id' => $this->intOrNull($to['org_unit_id'] ?? null),
            'project_id' => $this->intOrNull($to['project_id'] ?? null),
            'location_id' => $this->intOrNull($to['location_id'] ?? null),
        ];

        $current = [
            'custodian_id' => $this->intOrNull($asset->custodian_id),
            'org_unit_id' => $this->intOrNull($asset->org_unit_id),
            'project_id' => $this->intOrNull($asset->project_id),
            'location_id' => $this->intOrNull($asset->location_id),
        ];

        if ($target === $current) {
            throw new \DomainException(
                'That is where '.$asset->code.' already is. Nothing has been recorded.'
            );
        }

        // Configurable per company, because the two halves of the market genuinely differ: an oil
        // and gas operator wants a second signature against every movement of a calibrated
        // instrument, and a ten-person consultancy wants to hand over a laptop without ceremony.
        // Checked HERE rather than in the form request so that a seeder, an import and an API call
        // are held to the company's rule as well as a form is.
        $requiresAuthoriser = $this->settings->bool(
            'asset.require_authoriser_on_transfer',
            true,
            $asset->organization_id,
        );

        if ($requiresAuthoriser && $authorisedBy === null) {
            throw new \DomainException(
                'This company requires every custody handover to be authorised by a second person. '
                .'Name the authoriser, or turn the requirement off in Settings → Assets & fleet.'
            );
        }

        return DB::transaction(function () use ($asset, $target, $current, $actor, $reason, $condition, $remarks, $authorisedBy) {
            $transfer = new AssetCustodyTransfer([
                'reason' => $reason,
                'condition' => $this->condition($condition),
                'remarks' => $remarks,
            ]);

            $transfer->organization_id = $asset->organization_id;
            $transfer->asset_id = $asset->getKey();
            $transfer->transferred_at = now();
            $transfer->authorised_by = $authorisedBy;
            $transfer->created_by = $actor->getKey();

            foreach ($current as $column => $value) {
                $transfer->setAttribute('from_'.$column, $value);
            }

            foreach ($target as $column => $value) {
                $transfer->setAttribute('to_'.$column, $value);
            }

            $transfer->save();

            // The cache, second and in the same transaction. forceFill because these four are on
            // the service's own deny list — the point of that list is that no PAYLOAD reaches them,
            // not that this method cannot write them.
            $asset->forceFill($target)->save();

            // An asset sitting in a store is idle; one in somebody's hands is in service. Skipped
            // entirely while the asset is under maintenance, because being handed to the fitter is
            // not the same as coming back to work.
            if ($asset->statusKey() !== 'under_maintenance') {
                $this->moveTo($asset, $target['custodian_id'] === null && $target['project_id'] === null
                    ? 'idle'
                    : 'in_service');
                $asset->save();
            }

            $this->activity->record(
                $asset,
                'custody_transferred',
                $actor->name.' moved '.$asset->code.' from '.$transfer->fromLabel().' to '.$transfer->toLabel().'.',
                array_filter([
                    'condition' => $transfer->condition,
                    'reason' => $reason,
                ]),
                $actor,
                $target['project_id'],
            );

            // Both ends are notified, and the gate inside the listener decides who may actually be
            // told what (§68): the person losing an asset has as much reason to know as the person
            // receiving it, and neither of them is necessarily the person who typed it.
            AssetCustodyTransferred::dispatch($transfer);

            return $transfer;
        });
    }

    // ================================================================== life

    /**
     * Put a newly delivered thing on the register, with the delivery attached.
     *
     * THIS IS THE §90 JOINT ON THE ASSET SIDE. A generator that arrives on a purchase order becomes
     * an asset whose `goods_receipt_item_id` points back at the line it came in on — which points
     * at the order, which points at the requisition. "Why do we own this" is then answerable in
     * four clicks rather than by asking somebody who was there.
     *
     * The cost is taken from the receipt line rather than typed, for the same reason
     * `last_purchase_price` is: a field somebody can type is a field that says what the person
     * typing it wishes had been paid.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function registerFromReceipt(GoodsReceiptItem $line, array $attributes, User $actor): Asset
    {
        return DB::transaction(function () use ($line, $attributes, $actor) {
            $line = GoodsReceiptItem::query()->where('organization_id', $actor->organization_id)
                ->whereKey($line->getKey())->lockForUpdate()->firstOrFail();
            $line->load(['goodsReceipt.status', 'purchaseOrderItem.purchaseOrder']);

            if ($line->goodsReceipt?->statusKey() !== 'accepted' || (float) $line->quantity_accepted !== 1.0) {
                throw new \DomainException('Register an asset from an accepted receipt line containing exactly one item.');
            }
            if (Asset::withTrashed()->where('goods_receipt_item_id', $line->id)->exists()) {
                throw new \DomainException('This receipt line already has an asset. Open the existing asset instead.');
            }

            return $this->createFromReceiptLine($line, $attributes, $actor);
        });
    }

    private function createFromReceiptLine(GoodsReceiptItem $line, array $attributes, User $actor): Asset
    {
        // The source is evidence: a posted cost, vendor or receipt id cannot replace it.
        $orderLine = $line->purchaseOrderItem;
        $receipt = $line->goodsReceipt;
        $levels = ['public', 'internal', 'restricted', 'confidential'];
        $source = $orderLine?->purchaseOrder?->confidentiality;
        $source = $source instanceof \BackedEnum ? $source->value : ($source ?? 'internal');
        $receiptLevel = $receipt?->confidentiality;
        $receiptLevel = $receiptLevel instanceof \BackedEnum ? $receiptLevel->value : ($receiptLevel ?? 'internal');
        $requested = $attributes['confidentiality'] ?? 'internal';
        $requested = $requested instanceof \BackedEnum ? $requested->value : $requested;
        $attributes['confidentiality'] = $levels[max((int) array_search($source, $levels, true), (int) array_search($receiptLevel, $levels, true), (int) array_search($requested, $levels, true))];
        $order = $orderLine?->purchaseOrder;
        if ($order === null) {
            throw new \DomainException('The purchase order for this delivery is missing.');
        }
        $attributes['purchase_cost'] = FixedRateMoney::toBase(
            $orderLine->unit_price,
            $order->currency,
            $order->exchange_rate,
            $actor->organization?->currency,
        );
        $attributes['vendor_id'] = $orderLine?->purchaseOrder?->vendor_id;
        $attributes['acquired_on'] = $receipt?->received_at?->toDateString();

        /** @var Asset $asset */
        $asset = $this->create($attributes, $actor);

        $asset->forceFill([
            'goods_receipt_item_id' => $line->getKey(),
        ])->save();

        $this->activity->record(
            $asset,
            'linked_to_receipt',
            $actor->name.' linked '.$asset->code.' to goods receipt line #'.$line->getKey().'.',
            [],
            $actor,
        );

        return $asset;
    }

    /** Bring a registered asset into use. */
    public function commission(Asset $asset, User $actor): Asset
    {
        if ($asset->statusKey() !== 'draft') {
            return $asset;
        }

        return DB::transaction(function () use ($asset, $actor) {
            $this->moveTo($asset, 'idle');
            $asset->save();

            $this->activity->record(
                $asset,
                'commissioned',
                $actor->name.' brought '.$asset->code.' into service.',
                [],
                $actor,
            );

            return $asset;
        });
    }

    /**
     * The end of the asset's working life, and the end of nothing else.
     *
     * Custody is handed back through the ordinary ledger first — see the class docblock — so the
     * trail ends with a row saying the machine came back, rather than with the last person who had
     * it appearing to still have it.
     */
    public function retire(Asset $asset, User $actor, ?string $reference = null, ?string $reason = null): Asset
    {
        if ($asset->statusKey() === 'retired') {
            return $asset;
        }

        if ($asset->custodian_id !== null || $asset->project_id !== null
            || $asset->org_unit_id !== null || $asset->location_id !== null) {
            $this->transferCustody(
                $asset,
                ['custodian_id' => null, 'org_unit_id' => null, 'project_id' => null, 'location_id' => null],
                $actor,
                $reason ?? 'Returned on disposal',
                null,
                null,
                // Disposal is already the stronger authority, so it carries its own authorisation.
                // Requiring a second signature to hand a scrapped machine back to nobody would stop
                // the disposal rather than control it.
                $actor->getKey(),
            );
        }

        return DB::transaction(function () use ($asset, $actor, $reference, $reason) {
            $asset->forceFill([
                'disposed_on' => now()->toDateString(),
                'disposal_reference' => $reference,
            ]);

            $this->moveTo($asset, 'retired');
            $asset->save();

            $this->activity->record(
                $asset,
                'retired',
                $actor->name.' retired '.$asset->code.'.',
                array_filter(['reference' => $reference, 'reason' => $reason]),
                $actor,
            );

            return $asset;
        });
    }

    // ================================================================== helpers

    /** Upper case and single-spaced, exactly as store and stock codes are. */
    private function normaliseCode(?string $code): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $code) ?? ''));
    }

    /** Always one of the company's own words (§84). */
    private function category(mixed $value, User $actor): ?string
    {
        $allowed = $this->options->keys(
            'asset.categories',
            Asset::DEFAULT_CATEGORIES,
            $actor->organization_id,
        );

        $slug = blank($value) ? null : OptionList::slug((string) $value);

        return $slug !== null && in_array($slug, $allowed, true) ? $slug : null;
    }

    /**
     * A short fixed vocabulary, unlike the configurable lists.
     *
     * Condition is not company-specific in the way an asset category is: good, fair, poor and
     * damaged mean the same thing in every yard, and the value is read by the person deciding
     * whether the last custodian broke it.
     */
    private function condition(?string $condition): ?string
    {
        $condition = $condition === null ? null : mb_strtolower(trim($condition));

        return in_array($condition, AssetCustodyTransfer::CONDITIONS, true) ? $condition : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return blank($value) ? null : (int) $value;
    }
}
