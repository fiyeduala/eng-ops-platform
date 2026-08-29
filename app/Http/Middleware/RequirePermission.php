<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Engines\Access\AccessResolver;
use App\Engines\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level Gate 2 (section 6).
 *
 * Usage: ->middleware('permission:document.view') or 'permission:document.approve,document.review'
 * for "any of". This is a coarse first filter only — it answers "may this kind of user reach this
 * route at all". Record-level authorization still happens in the controller via a policy, because
 * a route check cannot know which record the URL refers to. Never treat this as sufficient.
 */
class RequirePermission
{
    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = Auth::user();

        if ($user === null) {
            abort(401);
        }

        if (! $this->resolver->hasAny($user, ...$permissions)) {
            $this->audit->logDenied(implode('|', $permissions), null, $user);

            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}
