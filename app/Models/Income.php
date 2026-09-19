<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Money the company has earned and is owed: a valuation certificate, a service call-out, scrap sold.
 *
 * NOT AN INVOICING ENGINE. Many companies raise the invoice itself in an accounting package, so
 * `invoice_reference` records whatever number the client was sent, and this row records what the
 * operation needs to know — which client, which contract, which project, how much, and whether it
 * has arrived. The receipt statuses are set by incoming payments, never by hand, so "received"
 * always has money behind it.
 */
class Income extends Model
{
    use HasCustomFields, RegisterRecord;

    public const DEFAULT_CATEGORIES = [
        'Contract billing', 'Variation', 'Service call-out', 'Equipment hire',
        'Retention release', 'Scrap and disposals', 'Other',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'income_date' => 'date',
            'due_on' => 'date',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'received_amount' => 'decimal:2',
            'confidentiality' => Confidentiality::class,
            'issued_at' => 'datetime',
        ];
    }

    public function accessAssigneeColumns(): array
    {
        return [];
    }

    public function registerConditionFields(): array
    {
        return ['category', 'total_amount', 'project_id', 'client_id'];
    }

    /** Due while it is still owed; received and cancelled rows are closed, so never overdue. */
    public function registerDueColumn(): ?string
    {
        return 'due_on';
    }

    public static function workflowEntityLabel(): string
    {
        return 'Income';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class)->withTrashed();
    }

    /** @return MorphMany<Payment,$this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function outstanding(): string
    {
        return (string) BigDecimal::of($this->total_amount ?? '0')->minus($this->received_amount ?? '0');
    }

    public function isReceivable(): bool
    {
        return in_array($this->statusKey(), ['issued', 'part_received'], true)
            && BigDecimal::of($this->outstanding())->isPositive();
    }
}
