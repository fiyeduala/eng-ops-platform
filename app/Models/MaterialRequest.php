<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Site asking the store for something the company already owns.
 *
 * THIS IS NOT A PURCHASE REQUEST AND MUST NEVER BE ALLOWED TO BECOME ONE. A purchase request says
 * "buy this"; this says "we have this, send it over". They look alike on screen and share nothing
 * underneath: this one names a warehouse rather than a supplier, is approved by the keeper's chain
 * rather than the buyer's, and terminates in a ledger row rather than an order. Where the store has
 * none of what is asked for, the answer is a purchase request RAISED FROM this one — a new record
 * naming this one, never this one mutating into it. A record that changes what it is loses its own
 * history, and the history is what somebody will be asked about.
 *
 * `quantity_approved` IS A DECISION AND IS STORED. `quantity_issued` IS ARITHMETIC AND IS NOT —
 * it is the sum of the ledger rows whose source is the line, so a reversal posted this afternoon
 * changes what this request says this afternoon. The keeper approving eight of the twelve asked for
 * is the commonest outcome in any store, and the eight is a judgement nobody can recompute.
 *
 * PARTIAL ISSUE IS THE NORMAL CASE, not an edge case. `issued` means the store has finished with
 * it, not that everything approved went out — a request closed with four of eight issued and a note
 * saying the rest is on order is an ordinary Tuesday.
 */
class MaterialRequest extends Model
{
    use HasCustomFields, RegisterRecord;

    protected $guarded = ['id'];

    /** `material_request.priorities`. The same four words as a purchase request, for the same reason. */
    public const DEFAULT_PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'required_by' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    // ------------------------------------------------------------------ relationships

    /** @return HasMany<MaterialRequestItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class)->orderBy('line_no');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * The approver and the storekeeper who has to act on it both reach it.
     *
     * `issued_by` is in the list even though it is stamped at the END of the process, which looks
     * backwards until you notice what it does after that: it keeps the request reachable by the
     * person who issued it, which is who gets asked about it a week later.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['approved_by', 'issued_by'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['priority'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Material requests';
    }

    // ------------------------------------------------------------------ state

    public function isAwaitingDecision(): bool
    {
        return $this->statusKey() === 'submitted';
    }

    /**
     * Whether stock may still go out against it.
     *
     * `part_issued` is included because that is exactly the state a second issue happens from — a
     * store that could not issue the rest of a part-issued request would force a second request for
     * the same materials, which is how duplicate demand gets into a system.
     */
    public function isIssuable(): bool
    {
        return in_array($this->statusKey(), ['approved', 'part_issued'], true);
    }

    /** @param  Builder<static>  $query */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->statusKey('submitted');
    }

    /** @param  Builder<static>  $query */
    public function scopePriority(Builder $query, ?string $priority): Builder
    {
        return blank($priority) ? $query : $query->where('priority', $priority);
    }

    // ------------------------------------------------------------------ presentation

    /** @return array<int,string> */
    public function registerSearchColumns(): array
    {
        return ['number', 'title'];
    }
}
