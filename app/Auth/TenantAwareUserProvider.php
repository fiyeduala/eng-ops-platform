<?php

declare(strict_types=1);

namespace App\Auth;

use App\Engines\Access\Scopes\OrganizationScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place the tenant global scope is deliberately not applied — and the reason it is safe.
 *
 * Gate 1 filters every tenant-scoped query by the organization currently in context. The context
 * is established by EnsureOrganizationContext, which reads it from the authenticated user. So
 * resolving the authenticated user cannot itself require a context: at the moment the session
 * guard looks the user up there is none, the scope would compile to `organization_id = 0`, and
 * every login in the system would fail closed. Authentication is the step that *produces* the
 * tenant; it cannot also be gated on it.
 *
 * This is not a hole, because the identifier being resolved is not user input. It comes from the
 * signed, encrypted session payload (or the remember-me cookie), both of which the framework has
 * already validated. Nothing a request can type reaches this query.
 *
 * Two consequences are load-bearing:
 *
 *   1. `retrieveByCredentials` is never given an `email` here — LoginController resolves the
 *      candidate accounts itself, precisely because email is unique PER ORGANIZATION and a naive
 *      `where('email', ...)->first()` would silently pick whichever tenant sorted first.
 *   2. Every other query against User — lists, lookups, policies — still runs through the global
 *      scope. Exempting authentication exempts nothing else.
 */
class TenantAwareUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     */
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)->withoutGlobalScope(OrganizationScope::class);
    }
}
