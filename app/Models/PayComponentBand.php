<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One slice of a progressive table.
 *
 * MARGINAL, ALWAYS. A band taxes only the portion of the basis that falls inside it, never the
 * whole figure — which is how every progressive system works and is therefore not a fact about any
 * particular one. Getting this wrong produces the cliff edge where earning one more unit of
 * currency costs somebody more than it earns them, which is the bug every taxpayer notices.
 *
 * `to_amount` null means "and everything above". The top band needs no special casing, and a table
 * cannot silently stop taxing at its highest figure because the person who typed it forgot a row.
 */
class PayComponentBand extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'sequence' => 'integer',
        ];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    /**
     * How much of `$basis` falls inside this band.
     *
     * Returns zero rather than a negative when the basis has not reached the band at all, so the
     * caller can sum every band unconditionally instead of working out which ones apply.
     */
    public function portionOf(BigDecimal $basis): BigDecimal
    {
        $from = BigDecimal::of($this->from_amount ?? '0');

        if ($basis->isLessThanOrEqualTo($from)) {
            return BigDecimal::zero();
        }

        $upper = $this->to_amount === null ? $basis : BigDecimal::of($this->to_amount);

        if ($basis->isLessThan($upper)) {
            $upper = $basis;
        }

        return $upper->minus($from);
    }
}
