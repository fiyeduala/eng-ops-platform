<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * One movement of cash into or out of a tin. Append-only, like the stock and cost ledgers.
 *
 * NO SCOPE OF ITS OWN. A transaction is read through its account — whoever may open the tin may read
 * what went through it — so it carries no visibility hooks that could disagree with the account's.
 */
class PettyCashTransaction extends Model
{
    use Auditable, BelongsToOrganization, HasFactory;

    public const UPDATED_AT = null;

    public const TYPES = [
        'replenishment' => 'Replenishment',
        'disbursement' => 'Disbursement',
        'reversal' => 'Reversal',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transacted_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException(
            'Petty cash transactions cannot be edited. Post a reversal instead.'
        ));
        static::deleting(fn () => throw new \LogicException(
            'Petty cash transactions cannot be deleted. Post a reversal instead.'
        ));
    }

    public static function permissionModule(): string
    {
        return 'petty_cash';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PettyCashAccount::class, 'petty_cash_account_id')->withTrashed();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** @return MorphOne<ProjectCost,$this> */
    public function projectCost(): MorphOne
    {
        return $this->morphOne(ProjectCost::class, 'source');
    }

    public function isReversible(): bool
    {
        return $this->type !== 'reversal' && ! $this->reversedBy()->exists();
    }

    public function displayName(): string
    {
        return $this->number.' — '.$this->description;
    }
}
