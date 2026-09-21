<?php

declare(strict_types=1);

namespace App\Models;

use App\Engines\Access\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One figure, counted over one scope, at one moment.
 *
 * READ THE RULE BEFORE USING THIS MODEL. A snapshot is counted over a WHOLE scope with no viewer
 * filtering at all — it is the company's number for that department, not anybody's view of it. That
 * makes it the one thing in the platform that can be shown to the wrong person, and the only guard
 * is that every read goes through `ReachInspector` first. There is no scope on this model that
 * filters by viewer, because there is no correct way to write one: the figure was never counted for
 * a viewer, so narrowing the ROW set would produce a smaller list of full-scope numbers rather than
 * a correct smaller number.
 *
 * So: never hand a snapshot to a screen without a `ReachInspector` verdict, and never write a scope
 * here that pretends to do it for you.
 *
 * A snapshot is also deliberately not `Auditable`. It is machine output, rewritten every morning by
 * the sweep, and auditing it would bury the audit trail under thousands of rows nobody changed by
 * hand. What a person edits is the DEFINITION, which is audited.
 */
class KpiSnapshot extends Model
{
    use BelongsToOrganization;

    /**
     * The altitudes a figure can be counted at.
     *
     * `employee` from KpiDefinition::SCOPES is absent on purpose — see the migration.
     *
     * @var array<string,string>
     */
    public const SCOPE_TYPES = [
        'organization' => 'The whole company',
        'department' => 'One department',
        'project' => 'One project',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'computed_at' => 'datetime',
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeForDefinition(Builder $query, KpiDefinition $kpi): Builder
    {
        return $query->where('kpi_definition_id', $kpi->getKey());
    }

    /**
     * One scope's series.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAtScope(Builder $query, string $scopeType, ?int $scopeId): Builder
    {
        return $query->where('scope_type', $scopeType)
            ->where(fn (Builder $q) => $scopeId === null
                ? $q->whereNull('scope_id')
                : $q->where('scope_id', $scopeId));
    }

    /**
     * Oldest first, which is the order a trend line is drawn in.
     *
     * By `period_key` rather than by `computed_at`: a point recomputed this morning for last month
     * still belongs where last month sits on the line.
     *
     * @param  Builder<self>  $query
     */
    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('period_key');
    }

    public function isAvailable(): bool
    {
        return $this->value !== null;
    }
}
