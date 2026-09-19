<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Expiry\Concerns\HasExpiryDates;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Somebody the company buys from.
 *
 * TWO QUESTIONS, NOT ONE. "Do we know who they are" and "are we allowed to place an order with
 * them" are different, and a vendor register that answers only the first is an address book. The
 * second is answered by three things that must all be true at once: an approved prequalification
 * status, a prequalification that has not expired, and no blacklisting. `canBeUsed()` is the single
 * place that conjunction lives, and every procurement service asks it rather than re-deriving it —
 * because the version that gets forgotten is always the expiry check.
 *
 * BLACKLISTING IS NOT A STATUS. It sits in its own column with its own permission, its own reason
 * and its own timestamp, because it is a decision about the company rather than a position in a
 * process. A vendor can be blacklisted from any status, un-blacklisting is a separate deliberate
 * act, and the reason is mandatory because "why can we not use them" is the question asked every
 * single time somebody's preferred supplier will not appear in a dropdown.
 *
 * THE BANK COLUMNS ARE THE MOST SENSITIVE FIELDS IN THE PLATFORM. Payment redirection fraud is a
 * quiet edit to an account number by somebody who legitimately holds `vendor.edit`. So they are
 * gated by `vendor.bank`, written only through VendorService::updateBankDetails(), excluded from
 * `registerSearchColumns()` so no search box can confirm a digit at a time (§61), and never
 * rendered by the ordinary show view. See VendorPolicy::viewBankDetails().
 *
 * THE RATING IS DERIVED, NOT TYPED. `rating` and `rated_count` are a running mean maintained by
 * VendorService::recordRating() from goods receipts and quotation evaluations. Nobody edits it
 * through a form: a supplier score somebody can type is a supplier score that says whatever its
 * author needs it to say this quarter.
 */
class Vendor extends Model
{
    use HasCustomFields, HasExpiryDates, RegisterRecord;

    /**
     * Prequalification is what makes a vendor eligible to be asked for a price at all. Letting it
     * lapse unnoticed means an award to a supplier the company no longer has grounds to trust, and
     * that is discovered during the audit of the purchase rather than before it.
     *
     * @var array<string,string>
     */
    protected static array $expiryDates = [
        'prequalification_expires_on' => 'Prequalification expires',
    ];

    protected $table = 'vendors';

    protected $guarded = ['id'];

    /** `vendor.types` — what kind of relationship this is, not what they sell. */
    public const DEFAULT_TYPES = ['Supplier', 'Subcontractor', 'Consultant', 'Service provider', 'Manufacturer'];

    /**
     * `vendor.categories` — what they supply, in this company's words.
     *
     * A starting list and nothing more. An offshore operator and a facilities company share almost
     * none of these, which is exactly why it is a setting rather than an enum (§32).
     */
    public const DEFAULT_CATEGORIES = [
        'Civil works', 'Mechanical', 'Electrical', 'Instrumentation', 'Piping and valves',
        'Structural steel', 'Consumables', 'PPE and safety equipment', 'Plant and equipment hire',
        'Calibration and testing', 'Inspection services', 'Logistics and haulage',
        'Professional services', 'IT and communications', 'Facilities and catering',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'prequalified_on' => 'date',
            'prequalification_expires_on' => 'date',
            'blacklisted_at' => 'datetime',
            'rating' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'is_blacklisted' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function contacts(): HasMany
    {
        return $this->hasMany(VendorContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function contracts(): MorphMany
    {
        return $this->morphMany(Contract::class, 'party')->orderByDesc('id');
    }

    public function blacklister(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blacklisted_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * A vendor belongs to the company, not to a job.
     *
     * The first register in the platform with no `project_id`, and the reason Scopeable has this
     * hook at all. Returning null rather than leaving the default would otherwise compile SQL
     * naming a column that does not exist — a 500 on the index page for every user whose access
     * scope is project-based, which is most site staff.
     */
    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['vendor_type', 'category', 'is_blacklisted', 'is_active'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Vendors';
    }

    // ------------------------------------------------------------------ state

    /**
     * May we place an order with them today?
     *
     * The conjunction, in one place. Every procurement service calls this instead of checking a
     * status — see the class docblock for why the hand-rolled version always forgets the expiry.
     */
    public function canBeUsed(): bool
    {
        return (bool) $this->is_active
            && ! $this->is_blacklisted
            && ! $this->prequalificationHasExpired()
            && $this->statusKey() !== 'suspended'
            && ! $this->isClosed();
    }

    /**
     * A prequalification with no expiry date does not expire.
     *
     * The alternative — treating a null as expired — would lock out every vendor a company added
     * before it started recording prequalification dates, on the day this column shipped.
     */
    public function prequalificationHasExpired(): bool
    {
        return $this->prequalification_expires_on !== null
            && $this->prequalification_expires_on->isBefore(now()->startOfDay());
    }

    public function prequalificationExpiresWithin(int $days): bool
    {
        return $this->prequalification_expires_on !== null
            && ! $this->prequalificationHasExpired()
            && $this->prequalification_expires_on->isBefore(now()->startOfDay()->addDays($days));
    }

    /** Why they cannot be used, in the words a buyer needs. Null when they can. */
    public function unusableReason(): ?string
    {
        return match (true) {
            (bool) $this->is_blacklisted => 'Blacklisted',
            ! $this->is_active => 'Inactive',
            $this->prequalificationHasExpired() => 'Prequalification expired',
            $this->statusKey() === 'suspended' => 'Suspended',
            $this->isClosed() => 'Not approved',
            default => null,
        };
    }

    public function hasBankDetails(): bool
    {
        return filled($this->bank_account_number) || filled($this->bank_name);
    }

    /**
     * The account number as it may be shown to somebody without `vendor.bank`.
     *
     * Last four only, and only where a human genuinely needs to confirm they are looking at the
     * right vendor — a payment run, a reconciliation. Never a full number, never in a list.
     */
    public function maskedAccountNumber(): ?string
    {
        $number = (string) ($this->bank_account_number ?? '');

        if ($number === '') {
            return null;
        }

        if (mb_strlen($number) <= 4) {
            return str_repeat('•', mb_strlen($number));
        }

        return str_repeat('•', mb_strlen($number) - 4).mb_substr($number, -4);
    }

    /** @param  Builder<static>  $query */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_blacklisted', false)
            ->where(function (Builder $q) {
                $q->whereNull('prequalification_expires_on')
                    ->orWhere('prequalification_expires_on', '>=', now()->startOfDay());
            });
    }

    /** @param  Builder<static>  $query */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('prequalification_expires_on')
            ->whereBetween('prequalification_expires_on', [
                now()->startOfDay(),
                now()->startOfDay()->addDays($days),
            ]);
    }

    // ------------------------------------------------------------------ presentation

    /**
     * NO BANK COLUMN APPEARS HERE, and that is a security decision rather than an oversight.
     *
     * A search box that matches on an account number reports, through the result count alone,
     * whether a guessed digit string belongs to a vendor — for any user who may list the register
     * at all (§61). The same argument rules out the tax id.
     *
     * @return array<int,string>
     */
    public function registerSearchColumns(): array
    {
        return ['number', 'name', 'code', 'legal_name', 'trade_name', 'city'];
    }

    public function registerDueColumn(): ?string
    {
        return 'prequalification_expires_on';
    }

    public function displayName(): string
    {
        return trim(($this->code ? $this->code.' — ' : '').(string) $this->name);
    }
}
