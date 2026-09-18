<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use App\Engines\Access\Concerns\Scopeable;
use App\Engines\Audit\Concerns\Auditable;
use App\Engines\Audit\Concerns\HasActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Where somebody's wages go.
 *
 * ITS OWN TABLE BECAUSE IT NEEDS ITS OWN PERMISSION (§67). The blast radius of `employee.view` — a
 * permission most of the workforce holds over somebody — must never include an account number. An
 * attacker who reaches the personnel register should find names and desks, not a payment file.
 *
 * ENCRYPTED AT REST, which makes the number unsearchable. That is the correct trade and not a
 * limitation: nobody has a legitimate reason to search the workforce BY account number, and a stolen
 * database dump should not be a ready-made list of everybody's banking details.
 *
 * `masked_tail` exists so a screen can say "…4417" without decrypting anything. Rendering a list of
 * two hundred people by decrypting two hundred account numbers would turn an ordinary page into a
 * bulk decryption of the entire workforce, which is both slow and precisely the operation an
 * intruder wants to trigger.
 */
class EmployeeBankAccount extends Model
{
    use Auditable, BelongsToOrganization, HasActivity, HasFactory, Scopeable, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * Never serialise the secrets.
     *
     * A model that reaches a JSON response, a queued job payload or a log line must not carry the
     * account number with it. The clear values are available to code that asks for them by name and
     * to nothing that merely passes the object around.
     */
    protected $hidden = ['account_number', 'branch_code'];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'branch_code' => 'encrypted',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Kept in step automatically, because a mask somebody has to remember to update is a mask
        // that will one day show the tail of an account the person no longer banks with.
        static::saving(function (self $account) {
            $number = $account->account_number;

            $account->masked_tail = is_string($number) && $number !== ''
                ? Str::substr(preg_replace('/\s+/', '', $number), -4)
                : null;
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ------------------------------------------------------------------ access

    public function accessParentRelation(): ?string
    {
        return 'employee';
    }

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

    // ------------------------------------------------------------------ behaviour

    /** What a screen may show without decrypting anything. */
    public function maskedNumber(): string
    {
        return $this->masked_tail === null ? '—' : '•••• '.$this->masked_tail;
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
