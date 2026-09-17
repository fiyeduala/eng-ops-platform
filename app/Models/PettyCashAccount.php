<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use App\Support\Enums\Confidentiality;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A cash tin: a site office float, a project imprest, the reception cash box.
 *
 * `balance` IS A CACHE. The truth is the sum of the transactions, which are never edited, and
 * `balanceMatchesLedger()` exists so a test — or a suspicious finance manager — can prove the two
 * agree. Every write goes through PettyCashService, which locks this row first, so two custodians
 * paying out of the same tin at once cannot both spend the last of it.
 */
class PettyCashAccount extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'float_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
            'confidentiality' => Confidentiality::class,
        ];
    }

    public static function permissionModule(): string
    {
        return 'petty_cash';
    }

    public function accessOwnerColumns(): array
    {
        return ['created_by'];
    }

    /** The custodian holds the tin, so they reach it at `assigned` scope. */
    public function accessAssigneeColumns(): array
    {
        return ['custodian_id'];
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PettyCashTransaction::class)->orderByDesc('id');
    }

    public function ledgerBalance(): string
    {
        $sum = PettyCashTransaction::query()->where('petty_cash_account_id', $this->getKey())->sum('amount');

        return (string) BigDecimal::of((string) ($sum ?: '0'))->toScale(2);
    }

    public function balanceMatchesLedger(): bool
    {
        return BigDecimal::of($this->ledgerBalance())->isEqualTo(BigDecimal::of($this->balance ?? '0'));
    }

    /** How much a replenishment may add before the tin passes its float; null when there is no ceiling. */
    public function headroom(): ?string
    {
        $float = BigDecimal::of($this->float_amount ?? '0');

        if (! $float->isPositive()) {
            return null;
        }

        return (string) BigDecimal::max(BigDecimal::zero(), $float->minus($this->balance ?? '0'))->toScale(2);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.trim($term).'%';

        return $query->where(fn (Builder $q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like));
    }

    public function displayName(): string
    {
        return $this->code.' — '.$this->name;
    }
}
