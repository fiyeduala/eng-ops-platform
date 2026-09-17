<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One tax a company charges or pays, at the rate it says (§47).
 *
 * NOTHING IN THE APPLICATION KNOWS WHAT ANY OF THESE ARE. "VAT 7.5 %", "WHT 5 %", "GST 10 %" and
 * "Exempt" are all rows a finance manager typed. The only behaviour attached to a code is the
 * arithmetic below and the recoverable flag, which decides whether the tax is a cost to the project
 * or money the company will claim back.
 *
 * A CODE IS NEVER RE-READ TO EXPLAIN AN OLD FIGURE. Every expense and income copies the rate and
 * the flag at the moment it is saved, so a rate change next April leaves last March's totals
 * exactly as they were approved.
 */
class TaxCode extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    public const APPLIES_TO = ['both' => 'Sales and purchases', 'sales' => 'Sales only', 'purchases' => 'Purchases only'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_recoverable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** A company-wide configuration row: it has no department and no project. */
    public function accessOrgUnitColumn(): ?string
    {
        return null;
    }

    public function accessProjectColumn(): ?string
    {
        return null;
    }

    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    /** Tax on a net amount, to the cent, rounding half up. */
    public static function taxOn(mixed $net, mixed $rate): string
    {
        return (string) BigDecimal::of($net ?? '0')
            ->multipliedBy(BigDecimal::of($rate ?? '0'))
            ->dividedBy(100, 2, RoundingMode::HALF_UP);
    }

    /** @param  Builder<static>  $query */
    public function scopeUsableFor(Builder $query, string $side): Builder
    {
        return $query->where('is_active', true)->whereIn('applies_to', ['both', $side]);
    }

    public function displayName(): string
    {
        return $this->code.' — '.$this->name.' ('.rtrim(rtrim((string) $this->rate, '0'), '.').'%)';
    }
}
