<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\PurchaseRequestDecided;
use App\Events\PurchaseRequestSubmitted;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Concerns\ManagesLineItems;
use App\Services\Concerns\SeparatesDuties;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Where buying starts: somebody needs something and says so.
 *
 * THE ESTIMATE IS A GUESS AND IS TREATED AS ONE. `estimated_total` is recomputed from the lines on
 * every save and can never be posted, because a header total that disagrees with its own lines is
 * the number an approver reads and the buyer then contradicts. It is an estimate all the way
 * through — the real figure arrives with the quotations, and nothing here pretends otherwise.
 *
 * THE REQUESTER IS NOT THE APPROVER, unless the company has said otherwise. That rule lives in
 * SeparatesDuties rather than in PurchaseRequestPolicy, and the reasoning is written out there: an
 * approver refused here holds the permission perfectly well, and a policy saying "denied" would be
 * telling them something untrue. When the control is off and the two are the same person, the
 * approval still happens and the activity entry says so.
 *
 * `ordered` IS NOT SET HERE BY A PERSON. `markOrdered()` is called by PurchaseOrderService when an
 * order is actually raised against the request, and there is deliberately no user-facing route to
 * it — a request marked ordered with no order behind it is a request whose requester is waiting for
 * something nobody has bought.
 *
 * WHAT IS DELIBERATELY ABSENT: any budget check. Budgets are part E, and a service that silently
 * approved everything today and started refusing things in three weeks would be worse than one that
 * has never claimed to check.
 */
class PurchaseRequestService extends RegisterService
{
    use ManagesLineItems, SeparatesDuties;

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
        return 'purchase_request';
    }

    protected function modelClass(): string
    {
        return PurchaseRequest::class;
    }

    /** @return class-string<Model> */
    protected function itemClass(): string
    {
        return PurchaseRequestItem::class;
    }

    protected function itemForeignKey(): string
    {
        return 'purchase_request_id';
    }

    /**
     * Every outcome column, plus the total. See the class docblock for the total.
     *
     * @return array<int,string>
     */
    protected function protectedAttributes(): array
    {
        return [
            'status_id', 'estimated_total',
            'submitted_by', 'submitted_at',
            'approved_by', 'approved_at',
            'rejected_by', 'rejected_at', 'decision_reason',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var PurchaseRequest $record */
        $record->priority = $this->priority($attributes['priority'] ?? null, $actor);

        // The company's own money, unless somebody says otherwise. A request with no currency reads
        // as free, and the person approving it has no way to know which of the two it is.
        if (blank($record->currency)) {
            $record->currency = $actor->organization?->currency;
        }

        $record->estimated_total = 0;
    }

    /** @param  array<string,mixed>  $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var PurchaseRequest $record */
        if (isset($attributes['items']) && is_array($attributes['items'])) {
            // Not recorded separately: the lines arrived as part of creating the request, and a
            // "changed the lines" entry a second after "opened" is noise.
            $this->syncItems($record, $attributes['items'], $actor, record: false);
            $this->recalculate($record);
        }
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var PurchaseRequest $record */
        if (array_key_exists('priority', $attributes)) {
            $attributes['priority'] = $this->priority($attributes['priority'], $actor);
        }

        $record = parent::update($record, $attributes, $actor);

        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor);
            $this->recalculate($record);
        }

        return $record;
    }

    // ================================================================== the decision

    /**
     * Send it to whoever approves purchasing here.
     */
    public function submit(PurchaseRequest $request, User $actor): PurchaseRequest
    {
        if ($request->statusKey() !== 'draft') {
            throw new \DomainException('Only a draft request can be submitted.');
        }

        // A request with nothing on it is a request nobody can price, quote or order against. The
        // check is here rather than in the form request because a seeder and an import reach the
        // same method (§84).
        if ($request->items()->doesntExist()) {
            throw new \DomainException('A purchase request cannot be submitted with no items on it.');
        }

        return DB::transaction(function () use ($request, $actor) {
            $this->recalculate($request);

            $request->forceFill([
                'submitted_by' => $request->submitted_by ?? $actor->getKey(),
                'submitted_at' => now(),
            ]);

            $this->moveTo($request, 'submitted');
            $request->save();

            $this->activity->record(
                $request,
                'submitted',
                $actor->name.' submitted '.$this->label($request).' for approval.',
                ['items' => $request->items()->count(), 'estimated_total' => (string) $request->estimated_total],
                $actor,
                $this->projectIdOf($request),
            );

            PurchaseRequestSubmitted::dispatch($request, (int) $actor->getKey());

            return $request;
        });
    }

    /**
     * Yes, buy it.
     *
     * The comment is optional here and required on rejection, which is not an oversight: "approved"
     * needs no explanation, "no" always does.
     */
    public function approve(PurchaseRequest $request, User $actor, ?string $comment = null): PurchaseRequest
    {
        if (! $request->isAwaitingDecision()) {
            throw new \DomainException('Only a submitted request can be approved.');
        }

        $waived = $this->assertSeparateDuties(
            $this->originatorOf($request),
            $actor,
            'procurement.separate_requester_and_approver',
            'You raised this request, so somebody else has to approve it.',
        );

        return DB::transaction(function () use ($request, $actor, $comment, $waived) {
            $request->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'decision_reason' => $comment,
            ]);

            $this->moveTo($request, 'approved');
            $request->save();

            $this->activity->record(
                $request,
                'approved',
                $actor->name.' approved '.$this->label($request).'.',
                array_filter([
                    'comment' => $comment,
                    // See SeparatesDuties: a waived control that leaves no trace is not a control.
                    'self_approved' => $waived ?: null,
                ]),
                $actor,
                $this->projectIdOf($request),
            );

            PurchaseRequestDecided::dispatch($request, (int) $actor->getKey());

            return $request;
        });
    }

    /**
     * No — and say why, because the requester has to decide what to do next.
     */
    public function reject(PurchaseRequest $request, User $actor, string $reason): PurchaseRequest
    {
        if (! $request->isAwaitingDecision()) {
            throw new \DomainException('Only a submitted request can be rejected.');
        }

        if (blank($reason)) {
            throw new \DomainException('A rejection has to say why.');
        }

        $waived = $this->assertSeparateDuties(
            $this->originatorOf($request),
            $actor,
            'procurement.separate_requester_and_approver',
            'You raised this request, so somebody else has to decide on it.',
        );

        return DB::transaction(function () use ($request, $actor, $reason, $waived) {
            $request->forceFill([
                'rejected_by' => $actor->getKey(),
                'rejected_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'decision_reason' => $reason,
            ]);

            $this->moveTo($request, 'rejected');
            $request->save();

            $this->activity->record(
                $request,
                'rejected',
                $actor->name.' rejected '.$this->label($request).'.',
                array_filter(['reason' => $reason, 'self_decided' => $waived ?: null]),
                $actor,
                $this->projectIdOf($request),
            );

            PurchaseRequestDecided::dispatch($request, (int) $actor->getKey());

            return $request;
        });
    }

    /**
     * An order now exists against this request. Called by PurchaseOrderService, not by a person.
     *
     * Silent when the request is not approved: an order may legitimately be raised against a
     * request that was cancelled after the buyer started work on it, and taking the order down
     * because the requisition moved on would be the wrong half of the chain to punish.
     */
    public function markOrdered(PurchaseRequest $request, User $actor, string $orderNumber): PurchaseRequest
    {
        if ($request->statusKey() !== 'approved') {
            return $request;
        }

        $this->moveTo($request, 'ordered');
        $request->save();

        $this->activity->record(
            $request,
            'ordered',
            'Purchase order '.$orderNumber.' was raised against '.$this->label($request).'.',
            ['purchase_order' => $orderNumber],
            $actor,
            $this->projectIdOf($request),
        );

        return $request;
    }

    // ================================================================== the lines

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function itemAttributes(array $row, Model $parent): array
    {
        $quantity = (float) ($row['quantity'] ?? 0);
        $rate = $row['estimated_unit_price'] ?? null;
        $item = $this->resolveStockItem($row);

        return [
            // The catalogue reference, where the requester chose one. It is what carries the line
            // through the order to the delivery and into the ledger (§90), and it is null far more
            // often than not — most of what a site asks for has never been a stock item.
            'stock_item_id' => $item?->getKey(),
            'description' => (string) $row['description'],
            'specification' => blank($row['specification'] ?? null) ? null : (string) $row['specification'],
            'quantity' => $quantity,
            'uom' => blank($row['uom'] ?? null) ? $item?->uom : (string) $row['uom'],
            'estimated_unit_price' => blank($rate) ? null : (float) $rate,
            // Null, not zero, when nobody priced it — see PurchaseRequestItem::computedTotal() for
            // why "costs nothing" and "nobody has said" must stay distinguishable.
            'estimated_total' => blank($rate) ? null : $this->lineTotal($quantity, $rate),
            'required_by' => blank($row['required_by'] ?? null) ? null : $row['required_by'],
            'notes' => blank($row['notes'] ?? null) ? null : (string) $row['notes'],
        ];
    }

    /**
     * Which catalogue item a line names, if it names one at all.
     *
     * MOST LINES NAME NOTHING, and that is the design. A requisition is how a site asks for what it
     * has not got, and a great deal of that has never been catalogued and never will be. The link
     * is an option on a free-text line, not a replacement for it.
     *
     * CHECKED HERE AS WELL AS IN THE FORM REQUEST because the form is one door. A seeder, an
     * import or another service is another, and an id from another organization must fail wherever
     * it arrives from (§84, §75).
     *
     * A NOTE ON UNITS, which is the one thing that can go quietly wrong: the ledger posts in the
     * CATALOGUE item's unit, so a line asking for "8 lengths" against an item held in metres would
     * put 8 metres on the shelf. The unit is therefore taken from the catalogue when the requester
     * left it blank, and where they typed one it is left alone and shown on the line for the buyer
     * to see.
     *
     * @param  array<string,mixed>  $row
     */
    private function resolveStockItem(array $row): ?StockItem
    {
        $id = (int) ($row['stock_item_id'] ?? 0);

        if ($id <= 0) {
            return null;
        }

        // Tenant-scoped, so another organization's catalogue is simply not there.
        $item = StockItem::query()->whereKey($id)->first();

        if ($item === null) {
            throw new \DomainException('That catalogue item could not be found.');
        }

        return $item;
    }

    /**
     * Add up the lines that carry a price and write the header total.
     *
     * Unpriced lines contribute nothing rather than blocking the sum, because a requisition where
     * half the lines have been priced is the normal state of one being written.
     */
    public function recalculate(PurchaseRequest $request): PurchaseRequest
    {
        $total = (string) $request->items()->sum('estimated_total');

        if ((string) $request->estimated_total !== number_format((float) $total, 2, '.', '')) {
            $request->forceFill(['estimated_total' => $total])->save();
        }

        return $request;
    }

    // ================================================================== helpers

    /**
     * Whose request this is, for the segregation check.
     *
     * `submitted_by` first, because the person who sent it forward is the one making the request of
     * the approver — a coordinator who types a request on an engineer's behalf and submits it is
     * the requester as far as this control is concerned. `created_by` is the fallback for a request
     * approved without a recorded submission, which the seeder and imports both produce.
     */
    private function originatorOf(PurchaseRequest $request): ?int
    {
        $id = $request->submitted_by ?? $request->created_by;

        return $id === null ? null : (int) $id;
    }

    /** Slugged, and always one of the company's own words (§84). */
    private function priority(mixed $value, User $actor): string
    {
        $allowed = $this->options->keys(
            'purchase_request.priorities',
            PurchaseRequest::DEFAULT_PRIORITIES,
            $actor->organization_id,
        );

        $slug = blank($value) ? null : OptionList::slug((string) $value);

        if ($slug !== null && in_array($slug, $allowed, true)) {
            return $slug;
        }

        // "normal" when it is on the list, otherwise whatever the company put first — a company
        // whose priorities are "routine, urgent" gets "routine" rather than a value it never
        // configured, and the column is never left empty.
        return in_array('normal', $allowed, true) ? 'normal' : (string) ($allowed[0] ?? 'normal');
    }
}
