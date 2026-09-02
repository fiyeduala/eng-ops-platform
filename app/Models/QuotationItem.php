<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One priced line from one supplier.
 *
 * `rfq_item_id` IS NULLABLE BECAUSE SUPPLIERS DO NOT ANSWER THE QUESTION AS ASKED. They split a
 * line into two, roll three into one, quote a substitute, or add a line for the freight nobody
 * mentioned. A comparison that assumed a one-to-one match with the enquiry would either refuse the
 * quotation or quietly discard the extra lines, and the extra lines are frequently the whole
 * difference between two prices.
 *
 * `is_alternative` IS THEIR ANSWER, NOT OUR JUDGEMENT. It means the supplier offered this INSTEAD
 * of what was asked for — a different make, a different rating, a longer delivery at a better
 * price. It stays out of the compared totals by default, because adding an alternative into the
 * sum makes a quotation look like it costs twice as much as it does.
 *
 * Reach is inherited from the parent quotation, which is where the confidentiality sits.
 */
class QuotationItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:2',
            'lead_time_days' => 'integer',
            'is_alternative' => 'boolean',
            'line_no' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** The line of the enquiry this answers, when it answers one at all. */
    public function rfqItem(): BelongsTo
    {
        return $this->belongsTo(RfqItem::class);
    }

    public function computedTotal(): string
    {
        return number_format((float) $this->quantity * (float) $this->unit_price, 2, '.', '');
    }

    public function label(): string
    {
        return trim(Quantity::withUnit($this->quantity, $this->uom).' — '.(string) $this->description);
    }
}
