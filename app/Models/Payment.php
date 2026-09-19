<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Money that actually moved: out to a supplier or a claimant, or in from a client.
 *
 * A PAYMENT ALWAYS SETTLES SOMETHING. An outgoing payment is against a purchase order or an approved
 * expense; an incoming one is against an issued income. There is no free-standing payment, because
 * a payment with nothing behind it is the one record an auditor asks about first.
 *
 * OUTGOING MONEY NEEDS TWO PEOPLE. Somebody approves the payment and somebody else marks it paid,
 * and the setting that relaxes that is recorded against the payment whenever it is used.
 *
 * A PAYMENT IS NOT A COST. The cost was posted when the goods were accepted or the expense approved;
 * paying it settles a debt. Posting again here would count every delivery twice.
 */
class Payment extends Model
{
    use HasCustomFields, RegisterRecord;

    public const DIRECTIONS = ['outgoing' => 'Outgoing', 'incoming' => 'Incoming'];

    public const DEFAULT_METHODS = ['Bank transfer', 'Cheque', 'Cash', 'Card', 'Mobile money'];

    /** Morph keys a payment may settle, by direction. */
    public const PAYABLES = [
        'outgoing' => ['purchase_order', 'expense'],
        'incoming' => ['income'],
    ];

    /** Statuses whose amount counts against what is still owed. */
    public const COMMITTED_STATUSES = ['draft', 'submitted', 'approved', 'paid'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'confidentiality' => Confidentiality::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function registerConditionFields(): array
    {
        return ['direction', 'method', 'amount', 'payable_type', 'vendor_id', 'client_id'];
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }

    public function registerSearchColumns(): array
    {
        return ['number', 'reference', 'payee_name'];
    }

    public function registerTitleColumn(): string
    {
        return 'payee_name';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Payments';
    }

    /** @return MorphTo<Model,$this> */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function isOutgoing(): bool
    {
        return $this->direction === 'outgoing';
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDirection(Builder $query, ?string $direction): Builder
    {
        return blank($direction) ? $query : $query->where('direction', $direction);
    }

    public function displayName(): string
    {
        return trim($this->number.' — '.($this->payee_name ?? ''), ' —');
    }
}
