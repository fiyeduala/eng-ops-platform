<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supplier's invitation to one enquiry.
 *
 * WHY THIS IS A MODEL AND NOT A PIVOT. "Who did we ask" is the question an auditor opens a
 * procurement file to answer, and the interesting answers are the negative ones: three of the five
 * we invited never replied, and the fourth declined because we owe them money. A pivot row with a
 * timestamp records the invitation; this records what happened to it.
 *
 * `responded_at` IS SET BY THE QUOTATION, not by somebody ticking a box. QuotationService stamps it
 * when a quotation is recorded against this enquiry for this vendor, because a response nobody can
 * produce a quotation for is not a response.
 *
 * `declined_at` AND `responded_at` ARE NOT EXCLUSIVE. Suppliers decline an enquiry and then quote
 * anyway more often than seems reasonable, and forcing one column to be null would make the record
 * disagree with the file.
 */
class RfqVendor extends Model
{
    use Auditable, BelongsToOrganization, HasFactory, Scopeable;

    protected $table = 'rfq_vendors';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function hasResponded(): bool
    {
        return $this->responded_at !== null;
    }

    public function hasDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    /** Asked, and neither quoted nor said no. The row an expediter chases. */
    public function isSilent(): bool
    {
        return ! $this->hasResponded() && ! $this->hasDeclined();
    }
}
