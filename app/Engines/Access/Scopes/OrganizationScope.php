<?php

declare(strict_types=1);

namespace App\Engines\Access\Scopes;

use App\Engines\Access\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Gate 1: organization isolation.
 *
 * Applied automatically by the BelongsToOrganization trait so it cannot be forgotten. An IDOR
 * attempt against another tenant's id yields zero rows, which surfaces as a 404 rather than a
 * leak. See docs/04-access-model.md section 9.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(OrganizationContext::class);

        if ($context->isSuspended()) {
            return;
        }

        $organizationId = $context->id();

        // No context bound means no tenant data is visible. Failing closed is deliberate:
        // a missing context must never widen access.
        $builder->where(
            $model->qualifyColumn('organization_id'),
            '=',
            $organizationId ?? 0
        );
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutOrganizationScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(self::class);
        });

        $builder->macro('forOrganization', function (Builder $builder, int $organizationId) {
            return $builder->withoutGlobalScope(self::class)
                ->where($builder->getModel()->qualifyColumn('organization_id'), $organizationId);
        });
    }
}
