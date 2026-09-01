<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RegisterRecord;
use App\Support\Enums\Confidentiality;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Somebody the company sells to.
 *
 * WHY THIS IS NOT THE VENDOR TABLE WITH A FLAG. On paper they are the same record — a name, a tax
 * id, contacts, an address — and every system that merges them ends up carrying a prequalification
 * status on rows nobody prequalifies and a blacklist reason on rows nobody blacklists. What
 * actually differs is the question being asked: a vendor is approved TO BE PAID, a client is
 * approved TO BE INVOICED, and those have different evidence, different owners and different
 * permissions. The handful of counterparties who are both get a row on each side, which is also
 * how a finance department already thinks about them.
 *
 * IT DOES NOT REWRITE `projects.client_name`. That column has held free text since Phase 2 and
 * keeps it. `projects.client_id` is the new, nullable link; the display layer prefers the linked
 * record where there is one and falls back to the typed name where there is not. Backfilling by
 * matching names would be a silent guess about which "Rivers Petroleum" was meant, made across
 * live commercial data — see the Phase 5 migration docblock.
 *
 * NO CREDIT ENFORCEMENT. `credit_limit` and `payment_terms_days` are recorded because people ask
 * for them on a client sheet, and nothing in the application blocks anything on their basis. A
 * credit control system is a different product, and half of one — a limit that stops some documents
 * and not others — is worse than none.
 */
class Client extends Model
{
    use RegisterRecord;

    protected $table = 'clients';

    protected $guarded = ['id'];

    /** `client.types` — the relationship, in this company's words. */
    public const DEFAULT_TYPES = [
        'Operator', 'EPC contractor', 'Main contractor', 'Government agency',
        'Private developer', 'Facilities owner', 'Consultant',
    ];

    /** `client.industries`. */
    public const DEFAULT_INDUSTRIES = [
        'Oil and gas', 'Power and energy', 'Water', 'Transport and infrastructure',
        'Buildings and property', 'Manufacturing', 'Mining', 'Telecommunications', 'Public sector',
    ];

    protected function casts(): array
    {
        return [
            'confidentiality' => Confidentiality::class,
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // ------------------------------------------------------------------ relationships

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function contracts(): MorphMany
    {
        return $this->morphMany(Contract::class, 'party')->orderByDesc('id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function relationshipOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'relationship_owner_id');
    }

    // ------------------------------------------------------------------ access + workflow

    /**
     * A client owns projects; it does not belong to one.
     *
     * Scoping the register by `project_id` would be backwards, and the column does not exist. See
     * Vendor::accessProjectColumn() for what the default would compile into.
     */
    public function accessProjectColumn(): ?string
    {
        return null;
    }

    /**
     * The relationship owner reaches their own accounts.
     *
     * `assigned` rather than `own`: whoever created the record is the owner, and account ownership
     * moves between people without the creation history changing.
     *
     * @return array<int,string>
     */
    public function accessAssigneeColumns(): array
    {
        return ['relationship_owner_id'];
    }

    /** @return array<int,string> */
    public function registerConditionFields(): array
    {
        return ['client_type', 'industry', 'is_active'];
    }

    public static function workflowEntityLabel(): string
    {
        return 'Clients';
    }

    // ------------------------------------------------------------------ presentation

    /** The tax id is excluded for the reason given on Vendor::registerSearchColumns(). */
    public function registerSearchColumns(): array
    {
        return ['number', 'name', 'code', 'legal_name', 'city'];
    }

    public function registerDueColumn(): ?string
    {
        return null;
    }

    public function displayName(): string
    {
        return trim(($this->code ? $this->code.' — ' : '').(string) $this->name);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
