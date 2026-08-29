<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Engines\Access\OrganizationContext;
use App\Engines\Audit\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant for the request, from the authenticated user and nowhere else
 * (sections 74, 75).
 *
 * There is deliberately no subdomain, header, query-string or session override. The only source
 * of truth for "which organization am I in" is the user row, so no request can ask to be
 * evaluated as another tenant.
 */
class EnsureOrganizationContext
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditContext $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->audit->fromRequest($request);

        $user = Auth::user();

        if ($user === null) {
            // Unauthenticated requests get no tenant. The global scope then fails closed, so a
            // stray query on a public page returns nothing rather than everything.
            $this->context->forget();

            return $next($request);
        }

        $organization = $user->organization;

        if ($organization === null) {
            return $this->reject($request, 'This account is not attached to an organization.');
        }

        if (! $organization->is_active) {
            return $this->reject($request, 'This organization is suspended. Please contact your administrator.');
        }

        if (! $user->canLogIn()) {
            return $this->reject($request, 'This account is not active.');
        }

        $this->context->set($organization);

        return $next($request);
    }

    /**
     * Terminate the session before aborting. A user whose account or tenant has just been
     * disabled must not keep a valid session cookie that works again the moment the flag flips.
     */
    private function reject(Request $request, string $message): never
    {
        Auth::logout();
        $this->context->forget();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(403, $message);
    }
}
