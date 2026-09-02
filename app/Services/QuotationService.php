<?php

declare(strict_types=1);

namespace App\Services;

use App\Engines\Audit\ActivityRecorder;
use App\Engines\Configuration\NumberGenerator;
use App\Engines\Configuration\SettingsRepository;
use App\Engines\Configuration\StatusMachine;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationScore;
use App\Models\Rfq;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Concerns\ManagesLineItems;
use App\Support\OptionList;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What the suppliers came back with, and what we thought of it.
 *
 * THE SUPPLIER AND THE ENQUIRY ARE RESOLVED, NEVER FILLED. Both arrive from a form as integers, and
 * both are looked up through the tenant-scoped query so another organization's ids simply do not
 * resolve (§75, §84). A quotation against an enquiry must come from a supplier who is on that
 * enquiry's invitation list — not because unsolicited offers do not happen, but because the list is
 * the record of who was asked, and a system that quietly extends it destroys the only evidence that
 * the competition was run the way it says it was.
 *
 * THE TOTAL IS ARITHMETIC. `subtotal` is the sum of the lines and `total` is subtotal plus whatever
 * tax figure was typed off the supplier's paperwork; neither can be posted. The tax is a FIGURE and
 * not a rule — nothing in this platform knows what VAT is, on purpose (§47).
 *
 * SCORES BELONG TO THE PERSON WHO GAVE THEM. `recordScores()` writes only the actor's own rows and
 * has no parameter for whose they are; QuotationScorePolicy refuses to let anybody edit anybody
 * else's. An evaluation where a manager can adjust a colleague's marks is not an evaluation, and
 * the weight and the label are copied onto each row so that changing the criteria list next quarter
 * does not silently restate what last quarter's panel decided.
 */
class QuotationService extends RegisterService
{
    use ManagesLineItems;

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
        return 'quotation';
    }

    protected function modelClass(): string
    {
        return Quotation::class;
    }

    /** @return class-string<Model> */
    protected function itemClass(): string
    {
        return QuotationItem::class;
    }

    protected function itemForeignKey(): string
    {
        return 'quotation_id';
    }

    /** @return array<int,string> */
    protected function protectedAttributes(): array
    {
        return ['status_id', 'rfq_id', 'vendor_id', 'subtotal', 'total'];
    }

    /** @param  array<string,mixed>  $attributes */
    protected function beforeCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Quotation $record */
        $rfq = $this->resolveRfq($attributes);
        $vendor = $this->resolveVendor($attributes);

        if ($rfq !== null) {
            if (! $rfq->acceptsQuotations()) {
                throw new \DomainException('This enquiry is no longer accepting quotations.');
            }

            if ($rfq->invitations()->where('vendor_id', $vendor->getKey())->doesntExist()) {
                throw new \DomainException(
                    $vendor->name.' is not on the invitation list for '.$rfq->number
                        .'. Add them to the enquiry first, so the record shows they were asked.'
                );
            }
        }

        $record->rfq_id = $rfq?->getKey();
        $record->vendor_id = $vendor->getKey();

        if (blank($record->currency)) {
            $record->currency = $actor->organization?->currency;
        }

        // Our own money needs no conversion, and a rate of 1 stated explicitly beats a null that
        // every downstream sum has to decide what to do with.
        if (blank($record->exchange_rate) && $record->currency === $actor->organization?->currency) {
            $record->exchange_rate = 1;
        }

        $record->subtotal = 0;
        $record->total = 0;
    }

    /** @param  array<string,mixed>  $attributes */
    protected function afterCreate(Model $record, array $attributes, User $actor): void
    {
        /** @var Quotation $record */
        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor, record: false);
        }

        $this->recalculate($record);

        // The invitation list is how "who responded" is answered, and it is answered by the arrival
        // of the quotation rather than by somebody ticking a box. See RfqVendor.
        if ($record->rfq_id !== null) {
            $record->rfq?->invitations()
                ->where('vendor_id', $record->vendor_id)
                ->whereNull('responded_at')
                ->update(['responded_at' => now()]);
        }
    }

    /** @param  array<string,mixed>  $attributes */
    public function update(Model $record, array $attributes, User $actor): Model
    {
        /** @var Quotation $record */
        $record = parent::update($record, $attributes, $actor);

        if (isset($attributes['items']) && is_array($attributes['items'])) {
            $this->syncItems($record, $attributes['items'], $actor);
        }

        $this->recalculate($record);

        return $record;
    }

    protected function creationNarrative(Model $record, User $actor): string
    {
        /** @var Quotation $record */
        return $actor->name.' recorded '.$this->label($record)
            .' from '.($record->vendor?->name ?? 'a supplier').'.';
    }

    // ================================================================== the lines and the total

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function itemAttributes(array $row, Model $parent): array
    {
        $quantity = (float) ($row['quantity'] ?? 0);
        $price = (float) ($row['unit_price'] ?? 0);

        return [
            'rfq_item_id' => $this->enquiryLineId($row, $parent),
            'description' => (string) $row['description'],
            'quantity' => $quantity,
            'uom' => blank($row['uom'] ?? null) ? null : (string) $row['uom'],
            'unit_price' => $price,
            'line_total' => $this->lineTotal($quantity, $price),
            'lead_time_days' => blank($row['lead_time_days'] ?? null) ? null : (int) $row['lead_time_days'],
            'is_alternative' => (bool) ($row['is_alternative'] ?? false),
            'notes' => blank($row['notes'] ?? null) ? null : (string) $row['notes'],
        ];
    }

    /**
     * A posted enquiry-line id is only accepted when it belongs to the enquiry being quoted.
     *
     * @param  array<string,mixed>  $row
     */
    private function enquiryLineId(array $row, Model $parent): ?int
    {
        /** @var Quotation $parent */
        $id = isset($row['rfq_item_id']) ? (int) $row['rfq_item_id'] : 0;

        if ($id <= 0 || $parent->rfq_id === null) {
            return null;
        }

        return $parent->rfq?->items()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Sum the lines, add the tax figure, write the total.
     *
     * Alternatives count. A supplier who offers a different pump instead of the one asked for has
     * quoted a price for the job, and leaving their alternative out of their own total would make
     * them look cheaper than they are on the comparison screen.
     */
    public function recalculate(Quotation $quotation): Quotation
    {
        $subtotal = number_format((float) $quotation->items()->sum('line_total'), 2, '.', '');
        $total = number_format((float) $subtotal + (float) $quotation->tax_amount, 2, '.', '');

        $quotation->forceFill(['subtotal' => $subtotal, 'total' => $total]);

        if ($quotation->isDirty()) {
            $quotation->save();
        }

        return $quotation;
    }

    // ================================================================== the evaluation

    /**
     * Record this user's marks. Theirs and nobody else's.
     *
     * @param  array<string,array{score:mixed,comment?:string|null}>  $marks  keyed by criterion key
     * @return int how many criteria were scored
     */
    public function recordScores(Quotation $quotation, User $actor, array $marks): int
    {
        $criteria = $this->options->for(
            'procurement.evaluation_criteria',
            Quotation::DEFAULT_CRITERIA,
            $quotation->organization_id,
        );

        $max = (float) $this->settings->int('procurement.score_out_of', 10, $quotation->organization_id) ?: 10.0;

        return DB::transaction(function () use ($quotation, $actor, $marks, $criteria, $max) {
            $written = 0;

            foreach ($marks as $key => $mark) {
                // Only the company's own criteria. A posted key that is not on the list is a column
                // on the evaluation screen that nobody agreed to score against (§84).
                if (! isset($criteria[$key])) {
                    continue;
                }

                $score = $mark['score'] ?? null;

                if (blank($score)) {
                    // Clearing a mark is a legitimate act — "I am not qualified to judge this one".
                    QuotationScore::query()
                        ->where('quotation_id', $quotation->getKey())
                        ->where('evaluated_by', $actor->getKey())
                        ->where('criterion_key', $key)
                        ->delete();

                    continue;
                }

                $score = max(0.0, min($max, (float) $score));

                QuotationScore::query()->updateOrCreate(
                    [
                        'quotation_id' => $quotation->getKey(),
                        'evaluated_by' => $actor->getKey(),
                        'criterion_key' => $key,
                    ],
                    [
                        'organization_id' => $quotation->organization_id,
                        // Copied, not looked up — see the class docblock and QuotationScore.
                        'criterion_label' => $criteria[$key],
                        'weight' => 1,
                        'score' => $score,
                        'max_score' => $max,
                        'comment' => blank($mark['comment'] ?? null) ? null : (string) $mark['comment'],
                    ],
                );

                $written++;
            }

            $quotation->unsetRelation('scores');

            if ($written > 0) {
                $this->activity->record(
                    $quotation,
                    'evaluated',
                    $actor->name.' scored '.$this->label($quotation).'.',
                    // The narrative says who and how many, never the marks: an activity entry is
                    // read by everybody who can see the record, and a score is an opinion about a
                    // supplier that belongs on the evaluation screen (§68).
                    ['criteria' => $written],
                    $actor,
                    $this->projectIdOf($quotation),
                );
            }

            return $written;
        });
    }

    // ================================================================== resolution

    /** @param  array<string,mixed>  $attributes */
    private function resolveRfq(array $attributes): ?Rfq
    {
        $id = isset($attributes['rfq_id']) ? (int) $attributes['rfq_id'] : 0;

        if ($id <= 0) {
            return null;
        }

        // Tenant-scoped, so another organization's enquiry is not found rather than refused — the
        // refusal must not confirm that the id exists somewhere.
        $rfq = Rfq::query()->whereKey($id)->first();

        if ($rfq === null) {
            throw new \DomainException('That enquiry could not be found.');
        }

        return $rfq;
    }

    /** @param  array<string,mixed>  $attributes */
    private function resolveVendor(array $attributes): Vendor
    {
        $id = isset($attributes['vendor_id']) ? (int) $attributes['vendor_id'] : 0;

        $vendor = $id > 0 ? Vendor::query()->whereKey($id)->first() : null;

        if ($vendor === null) {
            throw new \DomainException('A quotation has to say which supplier it came from.');
        }

        return $vendor;
    }
}
