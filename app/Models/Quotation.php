<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * What one supplier said they would charge.
 *
 * IT DEFAULTS TO CONFIDENTIAL, like the contract register and for the same reason. A live quotation
 * is a competitor's price: circulated internally it reaches somebody who knows somebody, and the
 * enquiry is compromised in a way nobody can prove afterwards. Starting internal and being narrowed
 * later means every quotation is readable company-wide for however long it takes somebody to
 * remember.
 *
 * TWO NUMBERS, BOTH KEPT. `number` is ours (QTN-2026-0004) and `reference` is theirs, verbatim off
 * their letterhead. A supplier chasing payment quotes their own; a buyer chasing a decision quotes
 * ours; and normalising one away means somebody has to translate on every phone call.
 *
 * `exchange_rate` IS FIXED WHEN THE QUOTATION IS ENTERED and never recalculated. A comparison that
 * revalues itself between the evaluation meeting and the award is a comparison that can change the
 * winner while nobody is looking. The rate that was used is part of the decision, so it is stored
 * with it.
 *
 * NO `is_accepted` FLAG. Acceptance is a property of the ENQUIRY — see Rfq. Two quotations can both
 * carry a flag and no query can then say which one won.
 *
 * NO `expired` STATUS. `valid_until` is a date; a quotation is out of date whether or not anybody
 * clicked anything, and a nightly job that expired them would silently rewrite a comparison the
 * morning after somebody printed it.
 */
class Quotation extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $table = 'quotations';

    protected $guarded = ['id'];

    /**
     * `procurement.evaluation_criteria`.
     *
     * FOUR WORDS THAT ARE NOT ALL ABOUT MONEY, which is the point. A company scoring only on price
     * does not need an evaluation module; the reason this exists is that the cheapest quotation is
     * regularly the wrong one, and the record has to show why the dearer one was chosen.
     */
    public const DEFAULT_CRITERIA = ['Price', 'Delivery', 'Technical compliance', 'Commercial terms'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'quoted_on' => 'date',
            'valid_until' => 'date',
            'exchange_rate' => 'decimal:8',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'lead_time_days' => 'integer',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** @return HasMany<QuotationItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('line_no');
    }

    /** @return HasMany<QuotationScore,$this> */
    public function scores(): HasMany
    {
        return $this->hasMany(QuotationScore::class);
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** Their PDF, in document control rather than as an attachment on this row. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<PurchaseOrder,$this> */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * Nothing. A quotation has no assignee — it is evaluated by whoever the enquiry names, and
     * their reach comes through the enquiry rather than through a column here.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['vendor_id', 'currency'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Quotations';
    }

    /** The one procurement register that starts confidential; see the class docblock. */
    public function defaultConfidentiality(): Confidentiality
    {
        return Confidentiality::Confidential;
    }

    // ------------------------------------------------------------------ state

    public function hasExpired(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isBefore(now()->startOfDay());
    }

    public function expiresWithin(int $days): bool
    {
        return $this->valid_until !== null
            && ! $this->hasExpired()
            && $this->valid_until->isBefore(now()->startOfDay()->addDays($days));
    }

    public function wasAwarded(): bool
    {
        return $this->statusKey() === 'accepted';
    }

    /**
     * The total converted into the organization's own currency.
     *
     * The rate is the one stored on this row, so the figure a comparison shows today is the figure
     * it showed at the evaluation meeting. Returns the raw total when no rate was recorded, which
     * is the normal case: most quotations are in the base currency and carry no rate at all.
     */
    public function totalInBaseCurrency(): string
    {
        $rate = (float) ($this->exchange_rate ?? 0);

        if ($rate <= 0.0) {
            return (string) $this->total;
        }

        return number_format((float) $this->total * $rate, 2, '.', '');
    }

    /**
     * The weighted average of every evaluator's scores, out of ten, or null when nobody has scored
     * it yet.
     *
     * WHY WEIGHTS COME FROM THE ROWS AND NOT FROM SETTINGS. Each score row stores the weight that
     * was configured when it was given, so re-weighting the criteria next quarter cannot silently
     * restate a decision taken last quarter. Reading the current settings here would do exactly
     * that.
     *
     * Expects `scores` to be loaded; it is a computation over rows the caller already has.
     */
    public function evaluationScore(): ?float
    {
        /** @var Collection<int,QuotationScore> $scores */
        $scores = $this->scores;

        if ($scores->isEmpty()) {
            return null;
        }

        $weighted = 0.0;
        $weight = 0.0;

        foreach ($scores as $score) {
            $max = (float) $score->max_score;

            if ($max <= 0.0) {
                continue;
            }

            $w = (float) $score->weight;
            $weighted += ((float) $score->score / $max) * 10 * $w;
            $weight += $w;
        }

        return $weight > 0.0 ? round($weighted / $weight, 2) : null;
    }

    /**
     * "Everything quoted on the refinery job", from a record that has no project of its own.
     *
     * A quotation belongs to an enquiry and the enquiry belongs to the project, so the shared filter
     * bar would otherwise be querying a column this table does not have. Going through the relation
     * also means the enquiry's own tenant scope applies, so another company's RFQ id widens nothing
     * (§75).
     *
     * @param  Builder<static>  $query
     */
    public function scopeForProject(Builder $query, int|string|null $projectId): Builder
    {
        return blank($projectId)
            ? $query
            : $query->whereHas('rfq', fn (Builder $q) => $q->where('project_id', $projectId));
    }

    /** @param  Builder<static>  $query */
    public function scopeForVendor(Builder $query, int|string|null $vendorId): Builder
    {
        return blank($vendorId) ? $query : $query->where('vendor_id', $vendorId);
    }

    // ------------------------------------------------------------------ presentation

    /** A quotation has no title of its own; their reference and our number identify it. */
    public function registerTitleColumn(): string
    {
        return 'reference';
    }

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'reference'];
    }

    /** A quotation is "due" when it stops being valid. */
    public function registerDueColumn(): ?string
    {
        return 'valid_until';
    }
}
