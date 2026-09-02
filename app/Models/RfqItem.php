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
 * One line of the enquiry.
 *
 * COPIED FROM THE REQUEST, NOT READ THROUGH IT. `purchase_request_item_id` records where the line
 * came from, and the description, quantity and unit are written here in full. A buyer routinely
 * rewrites what an engineer asked for, because what an engineer needs and what a supplier can price
 * are different sentences. Reading the text through to the request would silently rewrite what was
 * quoted every time the request is edited, and leave the quotations answering a question nobody
 * asked.
 *
 * NO PRICE. The whole point of an enquiry is that we do not know it yet. The estimate stays on the
 * request, where the approver saw it, and telling suppliers what we expect to pay is how a
 * competitive enquiry stops being one.
 *
 * Reach is inherited from the parent enquiry.
 */
class RfqItem extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'line_no' => 'integer',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function requestItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequestItem::class, 'purchase_request_item_id');
    }

    /** "12 m — 6in carbon steel pipe", for a comparison table that has no room for columns. */
    public function label(): string
    {
        return trim(sprintf(
            '%s %s — %s',
            Quantity::trim($this->quantity),
            (string) $this->uom,
            (string) $this->description,
        ));
    }
}
