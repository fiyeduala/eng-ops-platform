<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Events\MaterialRequestDecided;
use App\Events\MaterialRequestSubmitted;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\Concerns\ManagesLineItems;
use App\Services\Concerns\SeparatesDuties;
use App\Support\OptionList;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * "The site needs forty metres of cable out of the main store."
 *
 * THIS IS THE INTERNAL TWIN OF A PURCHASE REQUEST AND IS DELIBERATELY NOT THE SAME REGISTER. Both
 * are somebody asking for something and somebody else agreeing, and the temptation to give a
 * purchase request a `fulfil_from` of "stock" or "supplier" is strong. They were kept apart because
 * the two answer to different people and end in different places: a purchase request ends in an
 * order to a company we pay, and a material request ends in a storekeeper handing over goods the
 * company already owns. Merging them would mean one status graph trying to describe both "awaiting
 * the supplier" and "awaiting the store", and one approval chain covering both spending money and
 * moving it between shelves.
 *
 * THE STATUS IS COMPUTED FROM THE LEDGER, NOT TYPED. `part_issued` and `issued` are set by
 * `settleStatus()` from what the stock transactions actually say, which is why neither appears as a
 * clickable transition in StatusCatalogue. A person who can declare a request fully issued is a
 * person who can close a request while the site is still waiting for half of it — and the store's
 * own ledger already knows the answer.
 *
 * ISSUING IS NOT A COLUMN WRITE. Every issue goes through StockService, which means every issue is
 * a signed ledger row against a named store with a running balance and a refusal when the stock is
 * not there. `issue()` here is a loop that translates approved lines into movements and then reads
 * the result back; it holds no arithmetic of its own about what is on a shelf. That is the §90
 * chain closing on the inside of the company rather than at a supplier.
 *
 * THE RESERVATION IS OPTIONAL AND SHALLOW. When `inventory.reserve_on_approval` is on, approving a
 * request marks its quantities reserved on the balances so a second request cannot be approved
 * against the same drum. It is deliberately a number on the balance and not a table of holds: a
 * hold table needs expiry, release, partial release and a nightly reconciliation, and a company
 * that needs all four needs more than V1 offers. What is here is honest about being a hint —
 * `StockBalance::available()` is on hand minus reserved, and nothing refuses an issue because of it.
 */
class MaterialRequestService extends RegisterService
{
    use ManagesLineItems, SeparatesDuties;

    public function __construct(
        NumberGenerator $numbers,
        StatusMachine $statuses,
        ActivityRecorder $activity,
        private readonly SettingsRepository $settings,
        private readonly OptionList $options,
        private readonly StockService $stock,
    ) {
        parent::__construct($numbers, $statuses, $activity);
    }

    protected function entityType(): string
    {
        return 'material_request';
    }

    protected function modelClass(): string
    {
        return MaterialRequest::class;
    }

    /** @return class-string<Model> */
    protected function itemClass(): string
    {
        return MaterialRequestItem::class;
    }

    protected function itemForeignKey(): string
    {
        return 'material_request_id';
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return [
            'status_id',
            'submitted_by', 'submitted_at',
            'approved_by', 'approved_at',
            'issued_by', 'issued_at',
            'decision_notes',
        ];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var MaterialRequest $record */
        $record->priority = $this->priority($attributes['priority'] ?? null, $actor);
    }

    /** @param  array<string,mixed>  $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var MaterialRequest $record */
        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor, record: false);
        }
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var MaterialRequest $record */
        $wasDraft = $record->statusKey() === 'draft';

        if (array_key_exists('priority', $attributes)) {
            $attributes['priority'] = $this->priority($attributes['priority'], $actor);
        }

        // The store this comes out of is settled before anybody approves it, because the approval
        // is an approval to take stock off ONE set of shelves — a warehouse that can move after
        // approval turns an approved request into an approval of something else.
        if (array_key_exists('warehouse_id', $attributes) && $record->statusKey() !== 'draft') {
            unset($attributes['warehouse_id']);
        }

        $record = parent::update($record, $attributes, $actor);

        // THE LINES ARE ONLY EDITABLE IN DRAFT, and silently so rather than by refusal. Once the
        // request is approved, each line carries a `quantity_approved` somebody decided and a
        // string of ledger movements pointing at it; re-syncing the set would drop the approved
        // quantity of any line whose id the form did not send back, and delete outright any line
        // that has already been issued against. The header stays editable — a corrected delivery
        // note or a note to the storekeeper is exactly what `manage` is for.
        if (isset($attributes['items']) && is_array($attributes['items']) && $wasDraft) {
            $this->syncItems($record, $attributes['items'], $actor);
        }

        return $record;
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        return $actor->name.' raised '.$this->label($record).'.';
    }

    // ================================================================== the decision

    /**
     * Send it to whoever releases stock here.
     */
    public function submit(MaterialRequest $request, User $actor): MaterialRequest
    {
        if ($request->statusKey() !== 'draft') {
            throw new DomainException('Only a draft material request can be submitted.');
        }

        if ($request->items()->doesntExist()) {
            throw new DomainException('A material request cannot be submitted with nothing on it.');
        }

        // A request with no store named is a request nobody can pick. It is allowed to sit in draft
        // that way — half-written requests are normal — and refused at the point it becomes
        // somebody else's problem.
        if ($request->warehouse_id === null) {
            throw new DomainException('Say which store the material is to come out of before submitting.');
        }

        return DB::transaction(function () use ($request, $actor) {
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
                ['items' => $request->items()->count()],
                $actor,
                $this->projectIdOf($request),
            );

            MaterialRequestSubmitted::dispatch($request, (int) $actor->getKey());

            return $request;
        });
    }

    /**
     * Approve it, optionally for less than was asked for.
     *
     * THE APPROVED QUANTITY IS THE ONE THAT MATTERS FROM HERE ON. A store with sixty metres and a
     * request for a hundred is the ordinary case, and an approver who can only say yes or no to the
     * whole thing says no — after which the site raises the same request again for sixty and two
     * days have gone. So `$quantities` may cut a line down, keyed by item id, and everything
     * downstream issues against `quantity_approved`.
     *
     * A line approved at zero is a line refused, and stays on the request saying so. Deleting it
     * would leave the requester looking at a request that never mentioned what they asked for.
     *
     * @param  array<int|string,mixed>  $quantities  item id => approved quantity
     */
    public function approve(
        MaterialRequest $request,
        User $actor,
        array $quantities = [],
        ?string $notes = null,
    ): MaterialRequest {
        if (! $request->isAwaitingDecision()) {
            throw new DomainException('Only a submitted material request can be approved.');
        }

        $waived = $this->assertSeparateDuties(
            $this->originatorOf($request),
            $actor,
            'inventory.separate_approver_and_issuer',
            'You raised this request, so somebody else has to approve it.',
        );

        return DB::transaction(function () use ($request, $actor, $quantities, $notes, $waived) {
            $approved = 0.0;

            foreach ($request->items()->get() as $item) {
                /** @var MaterialRequestItem $item */
                $requested = (float) $item->quantity_requested;

                // Absent means "as asked for". A form that posts nothing for a line has not
                // silently refused it, and treating a missing key as zero would empty a request
                // whose approver only touched one line.
                $value = array_key_exists($item->getKey(), $quantities)
                    ? (float) $quantities[$item->getKey()]
                    : $requested;

                // Never more than was asked for. An approver who wants to send extra is starting a
                // different conversation, and the request in front of them is not the record of it.
                $value = max(0.0, min($value, $requested));

                $item->forceFill(['quantity_approved' => $value])->save();
                $approved += $value;
            }

            if ($approved <= 0) {
                throw new DomainException(
                    'Every line has been cut to nothing. Reject the request instead — the requester '
                    .'needs to know it was refused rather than approved for nothing.'
                );
            }

            $request->forceFill([
                'approved_by' => $actor->getKey(),
                'approved_at' => now(),
                'decision_notes' => $notes,
            ]);

            $this->moveTo($request, 'approved');
            $request->save();

            $this->reserveApproved($request, $actor);

            $this->activity->record(
                $request,
                'approved',
                $actor->name.' approved '.$this->label($request).' for issue.',
                array_filter([
                    'notes' => $notes,
                    'self_approved' => $waived ?: null,
                ]),
                $actor,
                $this->projectIdOf($request),
            );

            MaterialRequestDecided::dispatch($request, (int) $actor->getKey());

            return $request;
        });
    }

    /**
     * No — and say why.
     */
    public function reject(MaterialRequest $request, User $actor, string $reason): MaterialRequest
    {
        if (! $request->isAwaitingDecision()) {
            throw new DomainException('Only a submitted material request can be rejected.');
        }

        if (blank($reason)) {
            throw new DomainException('A rejection has to say why.');
        }

        $waived = $this->assertSeparateDuties(
            $this->originatorOf($request),
            $actor,
            'inventory.separate_approver_and_issuer',
            'You raised this request, so somebody else has to decide on it.',
        );

        return DB::transaction(function () use ($request, $actor, $reason, $waived) {
            $request->forceFill([
                'approved_by' => null,
                'approved_at' => null,
                'decision_notes' => $reason,
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

            MaterialRequestDecided::dispatch($request, (int) $actor->getKey());

            return $request;
        });
    }

    // ================================================================== the handover

    /**
     * Hand the material over: one ledger movement per line, then read back what happened.
     *
     * PARTIAL BY DESIGN AND REPEATABLE. `$quantities` says how much of each line is going out right
     * now; anything absent goes out in full, up to whatever is still outstanding. A store that
     * issues sixty metres today and forty next week calls this twice, and the request walks
     * `approved` → `part_issued` → `issued` on its own as the ledger fills in.
     *
     * THE REFUSAL COMES FROM THE LEDGER, NOT FROM HERE. If the shelf is empty, StockService throws
     * and the whole transaction rolls back — no line is issued, the request does not move, and
     * nothing has to be unwound. Issuing four lines and failing on the fifth would otherwise leave
     * a request the storekeeper has to reason about line by line.
     *
     * @param  array<int|string,mixed>  $quantities  item id => quantity going out now
     */
    public function issue(
        MaterialRequest $request,
        User $actor,
        array $quantities = [],
        ?string $notes = null,
    ): MaterialRequest {
        if (! $request->isIssuable()) {
            throw new DomainException('Only an approved material request can be issued against.');
        }

        $warehouse = $request->warehouse;

        if ($warehouse === null) {
            throw new DomainException('This request names no store to issue from.');
        }

        $waived = $this->assertSeparateDuties(
            [$request->approved_by],
            $actor,
            'inventory.separate_approver_and_issuer',
            'You approved this request, so somebody else has to issue against it.',
        );

        return DB::transaction(function () use ($request, $actor, $quantities, $notes, $warehouse, $waived) {
            $items = $request->items()->with('stockItem.status')->get();
            $movements = 0;

            foreach ($items as $item) {
                /** @var MaterialRequestItem $item */
                $outstanding = $item->quantityOutstanding();

                if ($outstanding <= 0) {
                    continue;
                }

                $going = array_key_exists($item->getKey(), $quantities)
                    ? (float) $quantities[$item->getKey()]
                    : $outstanding;

                $going = round(min(max($going, 0.0), $outstanding), 4);

                if ($going <= 0) {
                    continue;
                }

                $this->stock->issue($warehouse, $item->stockItem, $going, $actor, [
                    'project_id' => $request->project_id,
                    'org_unit_id' => $request->org_unit_id,
                    // The SOURCE IS THE LINE, not the request. A request for six items produces six
                    // movements, and pointing all six at the header would leave nobody able to say
                    // which one covered which line.
                    'source_type' => 'material_request_item',
                    'source_id' => $item->getKey(),
                    'reason' => 'Issued against '.$request->number,
                    'notes' => $notes,
                ]);

                /*
                 * THE HOLD ENDS WHERE THE MOVEMENT BEGINS. Approval reserved this quantity so
                 * nobody else was offered it; it has now physically left the shelf, and the
                 * on-hand figure has fallen by the same amount. Leaving the reservation in place
                 * would take the stock away twice — once from what is there and once from what is
                 * free — and the store would read as empty while the racks were full.
                 *
                 * Releasing here rather than at close() also means a request that is closed after
                 * a full issue has nothing left to give back, which is exactly what
                 * releaseReservations() then computes: approved, less issued, is nothing.
                 */
                if ($this->settings->bool('inventory.reserve_on_approval', true, $request->organization_id)) {
                    $this->stock->reserve($warehouse, $item->stockItem, -$going, $actor);
                }

                $movements++;
            }

            if ($movements === 0) {
                throw new DomainException('There is nothing left outstanding on this request to issue.');
            }

            $request->forceFill([
                'issued_by' => $actor->getKey(),
                'issued_at' => now(),
            ]);

            $this->settleStatus($request);
            $request->save();

            $this->activity->record(
                $request,
                'issued',
                $actor->name.' issued '.$movements.' '.($movements === 1 ? 'line' : 'lines')
                .' against '.$this->label($request).' from '.$warehouse->name.'.',
                array_filter([
                    'lines' => $movements,
                    'notes' => $notes,
                    'self_issued' => $waived ?: null,
                ]),
                $actor,
                $this->projectIdOf($request),
            );

            return $request;
        });
    }

    /**
     * Finish with the request, whether or not every line went out.
     *
     * The human act the status graph does have a button for. A request closed with lines still
     * outstanding is normal — the job finished, the rest was never needed — and it is a decision
     * somebody takes rather than something the arithmetic can work out.
     */
    public function close(MaterialRequest $request, User $actor, ?string $reason = null): MaterialRequest
    {
        if (! in_array($request->statusKey(), ['approved', 'part_issued', 'issued'], true)) {
            throw new DomainException('Only an approved or issued request can be closed.');
        }

        return DB::transaction(function () use ($request, $actor, $reason) {
            $this->releaseReservations($request, $actor);

            $this->moveTo($request, 'closed');
            $request->save();

            $this->activity->record(
                $request,
                'closed',
                $actor->name.' closed '.$this->label($request).'.',
                array_filter(['reason' => $reason]),
                $actor,
                $this->projectIdOf($request),
            );

            return $request;
        });
    }

    // ================================================================== the arithmetic

    /**
     * Set the status from what the ledger says, and nothing else.
     *
     * Called after issuing. Does not save — the caller is inside a transaction that has other
     * columns to write and one save is better than two.
     */
    private function settleStatus(MaterialRequest $request): void
    {
        $outstanding = 0.0;
        $issued = 0.0;

        foreach ($request->items()->get() as $item) {
            /** @var MaterialRequestItem $item */
            $outstanding += $item->quantityOutstanding();
            $issued += $item->quantityIssued();
        }

        if ($issued <= 0) {
            return;
        }

        $this->moveTo($request, $outstanding > 0 ? 'part_issued' : 'issued');
    }

    /**
     * Hold the approved quantities against the balances, when the company has asked for that.
     *
     * ONLY WHERE THERE IS STOCK TO HOLD. Reserving four hundred metres in a store that has sixty
     * produces an `available()` of minus three hundred and forty, which is a number nobody can act
     * on. The reservation is a hint to the next approver, so it holds what is actually there and
     * says nothing about the rest.
     */
    private function reserveApproved(MaterialRequest $request, User $actor): void
    {
        if (! $this->settings->bool('inventory.reserve_on_approval', true, $request->organization_id)) {
            return;
        }

        $warehouse = $request->warehouse;

        if ($warehouse === null) {
            return;
        }

        foreach ($request->items()->with('stockItem.status')->get() as $item) {
            /** @var MaterialRequestItem $item */
            $wanted = (float) ($item->quantity_approved ?? 0);

            if ($wanted <= 0 || $item->stockItem === null) {
                continue;
            }

            $this->stock->reserve($warehouse, $item->stockItem, $wanted, $actor);
        }
    }

    /**
     * Give back whatever is still being held. Called when the request is closed.
     *
     * Held quantities that nobody releases are the reason reservation systems get switched off
     * after six months: every closed request leaves a little stock permanently unavailable, and
     * eventually the store shows nothing free while the shelves are full. The release is deliberate
     * and mirrors the hold — what was reserved on approval, less what has actually gone out.
     */
    private function releaseReservations(MaterialRequest $request, User $actor): void
    {
        if (! $this->settings->bool('inventory.reserve_on_approval', true, $request->organization_id)) {
            return;
        }

        $warehouse = $request->warehouse;

        if ($warehouse === null) {
            return;
        }

        foreach ($request->items()->with('stockItem.status')->get() as $item) {
            /** @var MaterialRequestItem $item */
            $held = round((float) ($item->quantity_approved ?? 0) - $item->quantityIssued(), 4);

            if ($held <= 0 || $item->stockItem === null) {
                continue;
            }

            $this->stock->reserve($warehouse, $item->stockItem, -$held, $actor);
        }
    }

    // ================================================================== the lines

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function itemAttributes(array $row, Model $parent): array
    {
        $item = $this->resolveStockItem($row['stock_item_id'] ?? null);
        $quantity = (float) ($row['quantity_requested'] ?? $row['quantity'] ?? 0);

        return [
            'stock_item_id' => $item->getKey(),
            // The catalogue description is copied down rather than referenced, for the same reason
            // a purchase-order line copies its price: the request is a record of what was asked
            // for on the day, and renaming an item in the catalogue two years later must not
            // silently rewrite what somebody signed.
            'description' => blank($row['description'] ?? null)
                ? $item->name
                : (string) $row['description'],
            'quantity_requested' => $quantity,
            'uom' => blank($row['uom'] ?? null) ? $item->uom : (string) $row['uom'],
            'required_by' => blank($row['required_by'] ?? null) ? null : $row['required_by'],
            'notes' => blank($row['notes'] ?? null) ? null : (string) $row['notes'],
        ];
    }

    /**
     * A line with no catalogue item on it is not a line.
     *
     * THE DIFFERENCE FROM A PURCHASE-REQUEST LINE, which allows free text: you can ask a supplier
     * for something the company has never bought before, and you cannot take something off a shelf
     * that the store has no record of. If it is not in the catalogue it is not in the store, and
     * the honest answer is to catalogue it first.
     *
     * THE LOOKUP IS TENANT-SCOPED, which is the whole of the §84 check here — an id belonging to
     * another organization simply is not found, and the message is the same one an id that never
     * existed gets, so the field cannot be used to probe for what exists elsewhere (§75).
     */
    private function resolveStockItem(mixed $id): StockItem
    {
        $item = blank($id) ? null : StockItem::query()->find((int) $id);

        if ($item === null) {
            throw new DomainException('That item could not be found in the stock catalogue.');
        }

        if (! $item->isRequestable()) {
            throw new DomainException(
                $item->name.' is not in use and cannot be requested. A discontinued item can still '
                .'be issued against a request raised before it was withdrawn.'
            );
        }

        return $item;
    }

    /** @param  array<string,mixed>  $row */
    protected function itemIsEmpty(array $row): bool
    {
        return blank($row['stock_item_id'] ?? null)
            && (float) ($row['quantity_requested'] ?? $row['quantity'] ?? 0) <= 0;
    }

    // ================================================================== helpers

    /** Whose request this is, for the segregation check. See PurchaseRequestService. */
    private function originatorOf(MaterialRequest $request): ?int
    {
        $id = $request->submitted_by ?? $request->created_by;

        return $id === null ? null : (int) $id;
    }

    /** Slugged, and always one of the company's own words (§84). */
    private function priority(mixed $value, User $actor): string
    {
        $allowed = $this->options->keys(
            'material_request.priorities',
            MaterialRequest::DEFAULT_PRIORITIES,
            $actor->organization_id,
        );

        $slug = blank($value) ? null : OptionList::slug((string) $value);

        if ($slug !== null && in_array($slug, $allowed, true)) {
            return $slug;
        }

        return in_array('normal', $allowed, true) ? 'normal' : (string) ($allowed[0] ?? 'normal');
    }
}
