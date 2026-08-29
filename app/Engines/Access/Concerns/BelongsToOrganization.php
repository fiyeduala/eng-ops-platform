<?php

declare(strict_types=1);

namespace App\Engines\Access\Concerns;

use App\Engines\Access\OrganizationContext;
use App\Engines\Access\Scopes\OrganizationScope;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies Gate 1 to a model: the tenant global scope plus automatic organization_id population.
 *
 * Every tenant-scoped model MUST use this trait. A test in the suite enumerates the schema and
 * fails the build if a table carrying organization_id has a model that does not.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', app(OrganizationContext::class)->id());
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * True when this record belongs to the organization currently in context.
     * Used by policies as a belt-and-braces check alongside the global scope.
     */
    public function belongsToCurrentOrganization(): bool
    {
        $contextId = app(OrganizationContext::class)->id();

        return $contextId !== null && (int) $this->getAttribute('organization_id') === $contextId;
    }
}
